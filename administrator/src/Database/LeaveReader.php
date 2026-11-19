<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * My Leaves and My Approvals (1.1.0): leave requests and their approval stages, read from
 * ZKBioTime's database (read-only; the requests are made and approved in ZKBioTime).
 *
 *  - A leave request is a workflow instance (workflow_workflowinstance: employee, overall status)
 *    with its leave (att_leave: dates, days, reason, attachment, leave type = att_paycode).
 *  - Each approval stage of a request is a node instance (workflow_nodeinstance: order, status,
 *    who decided and when, remark; `active` marks the stage the request is at).
 *  - Who approves a stage: the ZKBioTime roles of the stage (workflow_workflownode_approver), held
 *    by employees (personnel_employee_flow_role); or the employee a stage was sent to (targeted).
 *
 * Only the logged-in employee's own requests, or those waiting for them or decided by them, are
 * read. The attachment is only its file name (the file stays on the ZKBioTime server).
 */
final class LeaveReader
{
    /** At most this many requests per list. */
    public const MAX_ROWS = 200;

    private ?Query $query = null;

    /**
     * @param \Closure(): Query $connect   opens the connection when first needed
     * @param string            $timezone  the ZKBioTime server's time zone: dates are shown in it
     */
    public function __construct(private readonly \Closure $connect, private readonly string $timezone = 'UTC')
    {
    }

    /**
     * The employee's own leave requests, newest first, with their stages.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException QUERY when the tables cannot be read (not granted), else when unreachable
     */
    public function requestsOf(int $employeeId): array
    {
        $rows = $this->q()->rows(self::SELECT . self::FROM . ' WHERE w.employee_id = :emp ORDER BY l.apply_time DESC, w.id DESC LIMIT ' . self::MAX_ROWS, [':emp' => $employeeId]);

        return $this->withStages(array_map(fn (array $r): array => $this->request($r), $rows));
    }

    /**
     * Requests waiting at a stage this employee approves: the stage the request is at (active, not
     * decided yet) lists one of the employee's ZKBioTime approver roles, or was sent to them.
     * Oldest first (the longest waiting at the top).
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function waitingFor(int $employeeId): array
    {
        $rows = $this->q()->rows(
            self::SELECT . self::FROM . '
             JOIN workflow_nodeinstance ni ON ni.workflow_instance_id = w.id
            WHERE ni.active = TRUE AND ni.approval_time IS NULL
              AND w.employee_id <> :self
              AND ((ni.targeted = TRUE AND ni.approver_employee_id = :emp)
                   OR EXISTS (SELECT 1 FROM workflow_workflownode_approver a
                                JOIN personnel_employee_flow_role f ON f.workflowrole_id = a.workflowrole_id
                               WHERE a.workflownode_id = ni.workflow_node_id AND f.employee_id = :role_emp))
            ORDER BY l.apply_time ASC, w.id ASC LIMIT ' . self::MAX_ROWS,
            [':emp' => $employeeId, ':self' => $employeeId, ':role_emp' => $employeeId]
        );

        return $this->withStages($this->unique(array_map(fn (array $r): array => $this->request($r), $rows)));
    }

    /**
     * Stages this employee decided (approved or rejected), most recent first, with the request.
     *
     * @return list<array<string, mixed>> each request with 'decision' => the employee's stage
     *
     * @throws DbException
     */
    public function decidedBy(int $employeeId): array
    {
        $rows = $this->q()->rows(
            self::SELECT . ', ni.id AS decision_id, ni.node_name AS decision_stage, ni.approval_status AS decision_status,
                    ni.approval_time AS decision_time, ni.approval_remark AS decision_remark' . self::FROM . '
             JOIN workflow_nodeinstance ni ON ni.workflow_instance_id = w.id
            WHERE ni.approver_employee_id = :emp AND ni.approval_time IS NOT NULL
            ORDER BY ni.approval_time DESC LIMIT ' . self::MAX_ROWS,
            [':emp' => $employeeId]
        );

        return $this->withStages(array_map(function (array $r): array {
            return $this->request($r) + ['decision' => [
                'stage'  => self::text($r['decision_stage'] ?? null) ?? '',
                'status' => (int) ($r['decision_status'] ?? -1),
                'time'   => $this->time($r['decision_time'] ?? null),
                'remark' => self::text($r['decision_remark'] ?? null, 500),
            ]];
        }, $rows));
    }

    /** The columns of a request; FROM: the tables they come from (decidedBy() adds columns between). */
    private const SELECT = 'SELECT w.id, w.approval_status, w.approval_time, w.approval_remark,
                    l.start_time, l.end_time, l.apply_time, l.apply_reason, l.attachment, l.leave_day,
                    p.name AS leave_name, e.emp_code, e.first_name, e.last_name, d.dept_name';

    private const FROM = ' FROM att_leave l
             JOIN workflow_workflowinstance w ON w.id = l.workflowinstance_ptr_id
             JOIN personnel_employee e ON e.id = w.employee_id
             LEFT JOIN personnel_department d ON d.id = e.department_id
             LEFT JOIN att_paycode p ON p.id = l.pay_code_id';

    /** @param list<array<string, mixed>> $requests */
    private function withStages(array $requests): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $r): int => $r['id'], $requests)));

        if ($ids === []) {
            return $requests;
        }

        // Integers only (cast above), so the list is safe in the statement.
        $rows = $this->q()->rows(
            'SELECT ni.workflow_instance_id, ni.node_name, ni.order_id, ni.approval_status, ni.approval_time, ni.approval_remark,
                    ni.active, e.first_name, e.last_name
               FROM workflow_nodeinstance ni
               LEFT JOIN personnel_employee e ON e.id = ni.approver_employee_id
              WHERE ni.workflow_instance_id IN (' . implode(',', $ids) . ')
              ORDER BY ni.workflow_instance_id, ni.order_id, ni.id'
        );
        $stages = [];

        foreach ($rows as $r) {
            $stages[(int) $r['workflow_instance_id']][] = [
                'name'     => self::text($r['node_name'] ?? null) ?? '',
                'order'    => (int) ($r['order_id'] ?? 0),
                'status'   => (int) ($r['approval_status'] ?? -1),
                'time'     => $this->time($r['approval_time'] ?? null),
                'remark'   => self::text($r['approval_remark'] ?? null, 500),
                'current'  => self::flag($r['active'] ?? false) && ($r['approval_time'] ?? null) === null,
                'approver' => self::name($r['first_name'] ?? null, $r['last_name'] ?? null),
            ];
        }

        return array_map(static fn (array $r): array => $r + ['stages' => $stages[$r['id']] ?? []], $requests);
    }

    /** @param list<array<string, mixed>> $requests */
    private function unique(array $requests): array
    {
        $seen = [];

        return array_values(array_filter($requests, static function (array $r) use (&$seen): bool {
            if (isset($seen[$r['id']])) {
                return false;
            }

            return $seen[$r['id']] = true;
        }));
    }

    /** @param array<string, mixed> $r */
    private function request(array $r): array
    {
        $attachment = self::text($r['attachment'] ?? null, 255);

        return [
            'id'          => (int) $r['id'],
            'status'      => (int) ($r['approval_status'] ?? -1),
            'decided'     => $this->time($r['approval_time'] ?? null),
            'remark'      => self::text($r['approval_remark'] ?? null, 500),
            'from'        => $this->time($r['start_time'] ?? null),
            'to'          => $this->time($r['end_time'] ?? null),
            'applied'     => $this->time($r['apply_time'] ?? null),
            'reason'      => self::text($r['apply_reason'] ?? null, 500),
            // Only the file's name: the file itself stays on the ZKBioTime server.
            'attachment'  => $attachment !== null ? basename(str_replace('\\', '/', $attachment)) : null,
            'days'        => is_numeric($r['leave_day'] ?? null) ? (float) $r['leave_day'] : null,
            'leave'       => self::text($r['leave_name'] ?? null) ?? '',
            'emp_code'    => self::text($r['emp_code'] ?? null) ?? '',
            'employee'    => self::name($r['first_name'] ?? null, $r['last_name'] ?? null) ?? (self::text($r['emp_code'] ?? null) ?? ''),
            'department'  => self::text($r['dept_name'] ?? null),
        ];
    }

    /** "Y-m-d H:i" in the ZKBioTime server's time zone; null when empty or unreadable. */
    private function time(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($this->timezone))->format('Y-m-d H:i');
        } catch (\Exception) {
            return null;
        }
    }

    private static function name(mixed $first, mixed $last): ?string
    {
        $name = trim(implode(' ', array_filter([self::text($first), self::text($last)])));

        return $name === '' ? null : $name;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function text(mixed $value, int $max = 100): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim(strip_tags((string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function q(): Query
    {
        return $this->query ??= ($this->connect)();
    }
}
