<?php

declare(strict_types=1);

namespace GMA\Repository;

/**
 * One row per post (UNIQUE KEY post_id). Claims use a lease (locked_until)
 * plus a fencing token (claim_token): a worker whose lease expired and whose
 * job was re-claimed by someone else can no longer write its result.
 * Attempts are counted at claim time, so a job that keeps crashing its
 * worker still reaches the cap.
 */
final class GeoJobRepository
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_ERROR = 'error';

    private const CLAIM_CANDIDATES = 5;

    public static function installTable(): void
    {
        global $wpdb;

        $table = self::table();
        $charsetCollate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta is picky: two spaces before PRIMARY/UNIQUE/KEY, one column per line.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            address VARCHAR(500) NOT NULL,
            address_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_retry_at DATETIME NULL,
            locked_until DATETIME NULL,
            claim_token CHAR(32) NULL,
            lat DECIMAL(10,7) NULL,
            lng DECIMAL(10,7) NULL,
            provider VARCHAR(30) NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY status (status)
        ) {$charsetCollate};";

        dbDelta($sql);
    }

    public static function hashAddress(string $address): string
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $address) ?? $address));

        return hash('sha256', $normalized);
    }

    /**
     * Idempotent registration per post. Same address → no-op. Changed address
     * → back to pending, and the claim is cleared so a worker still holding
     * the old address cannot write its result.
     *
     * @return array{id: int, changed: bool}
     */
    public function register(int $postId, string $address, int $now): array
    {
        global $wpdb;

        $table = self::table();
        $hash = self::hashAddress($address);
        $ts = self::ts($now);

        $existing = $this->findRowByPost($postId);

        if ($existing === null) {
            $inserted = $wpdb->insert($table, [
                'post_id' => $postId,
                'address' => $address,
                'address_hash' => $hash,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);

            if ($inserted) {
                return ['id' => (int) $wpdb->insert_id, 'changed' => true];
            }

            // Lost a race to a concurrent register for the same post (UNIQUE KEY).
            $existing = $this->findRowByPost($postId);
        }

        $id = (int) $existing['id'];

        if ($existing['address_hash'] === $hash) {
            return ['id' => $id, 'changed' => false];
        }

        $wpdb->update($table, [
            'address' => $address,
            'address_hash' => $hash,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_retry_at' => null,
            'locked_until' => null,
            'claim_token' => null,
            'lat' => null,
            'lng' => null,
            'provider' => null,
            'error_message' => null,
            'updated_at' => $ts,
        ], ['id' => $id]);

        return ['id' => $id, 'changed' => true];
    }

    public function deleteByPost(int $postId): void
    {
        global $wpdb;

        $wpdb->delete(self::table(), ['post_id' => $postId]);
    }

    /**
     * Atomically claims the next eligible job: pending and due, or processing
     * with an expired lease. The claim is a single conditional UPDATE. If it
     * matches zero rows, another worker got there first and we try the next
     * candidate.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(int $now, int $leaseSeconds, int $maxAttempts): ?array
    {
        global $wpdb;

        $table = self::table();
        $ts = self::ts($now);

        $this->expireExhaustedLeases($now, $maxAttempts);

        [$clause, $args] = self::claimable($ts, $maxAttempts);

        for ($i = 0; $i < self::CLAIM_CANDIDATES; $i++) {
            $candidateId = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE {$clause} ORDER BY id ASC LIMIT 1", ...$args)
            );

            if ($candidateId === null) {
                return null;
            }

            $claimed = $this->claimById((int) $candidateId, $now, $leaseSeconds, $maxAttempts);

            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Claims one specific job under the same conditions as claimNext(). Used
     * by the single-post "geocode now" action.
     *
     * @return array<string, mixed>|null
     */
    public function claimById(int $id, int $now, int $leaseSeconds, int $maxAttempts): ?array
    {
        global $wpdb;

        $table = self::table();
        $ts = self::ts($now);
        $token = bin2hex(random_bytes(16));

        [$clause, $args] = self::claimable($ts, $maxAttempts);

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = %s, attempts = attempts + 1, claim_token = %s, locked_until = %s, updated_at = %s
             WHERE id = %d AND {$clause}",
            self::STATUS_PROCESSING,
            $token,
            self::ts($now + $leaseSeconds),
            $ts,
            $id,
            ...$args
        ));

        return $affected === 1 ? $this->getById($id) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByPostId(int $postId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE post_id = %d", $postId),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Protects against poison jobs: a claim whose lease expired after the last
     * allowed attempt becomes terminal instead of sitting in processing forever.
     */
    public function expireExhaustedLeases(int $now, int $maxAttempts): int
    {
        global $wpdb;

        $ts = self::ts($now);

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
             SET status = %s, error_message = %s, claim_token = NULL, locked_until = NULL, updated_at = %s
             WHERE status = %s AND locked_until < %s AND attempts >= %d",
            self::STATUS_ERROR,
            'lease expired after the last allowed attempt',
            $ts,
            self::STATUS_PROCESSING,
            $ts,
            $maxAttempts
        ));
    }

    public function markDone(int $id, string $token, float $lat, float $lng, string $provider, int $now): bool
    {
        return $this->finish($id, $token, $now, [
            'status' => self::STATUS_DONE,
            'lat' => $lat,
            'lng' => $lng,
            'provider' => $provider,
            'error_message' => null,
        ]);
    }

    public function markNotFound(int $id, string $token, int $now): bool
    {
        return $this->finish($id, $token, $now, [
            'status' => self::STATUS_NOT_FOUND,
            'error_message' => null,
        ]);
    }

    public function markFailed(int $id, string $token, string $message, int $now): bool
    {
        return $this->finish($id, $token, $now, [
            'status' => self::STATUS_ERROR,
            'error_message' => $message,
        ]);
    }

    /**
     * Transient failure: back to pending with a delay while attempts remain,
     * otherwise terminal error. The decision is made in SQL on the attempts
     * counter, so it cannot be read stale.
     */
    public function markRetryLater(int $id, string $token, string $message, int $delaySeconds, int $now, int $maxAttempts): bool
    {
        global $wpdb;

        $table = self::table();
        $ts = self::ts($now);

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = IF(attempts < %d, %s, %s),
                 next_retry_at = IF(attempts < %d, %s, NULL),
                 error_message = %s, claim_token = NULL, locked_until = NULL, updated_at = %s
             WHERE id = %d AND claim_token = %s AND status = %s",
            $maxAttempts,
            self::STATUS_PENDING,
            self::STATUS_ERROR,
            $maxAttempts,
            self::ts($now + $delaySeconds),
            $message,
            $ts,
            $id,
            $token,
            self::STATUS_PROCESSING
        ));

        return $affected === 1;
    }

    /**
     * The provider is unusable for everyone (circuit open, blocked). The
     * failure says nothing about this address, so the attempt claimed above
     * is given back.
     */
    public function releaseSystemic(int $id, string $token, string $message, int $now): bool
    {
        global $wpdb;

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
             SET status = %s, attempts = GREATEST(attempts - 1, 0), claim_token = NULL, locked_until = NULL,
                 error_message = %s, updated_at = %s
             WHERE id = %d AND claim_token = %s AND status = %s",
            self::STATUS_PENDING,
            $message,
            self::ts($now),
            $id,
            $token,
            self::STATUS_PROCESSING
        ));

        return $affected === 1;
    }

    /**
     * Manual retry from the dashboard. Only terminal jobs qualify, so a
     * double click cannot queue the same job twice.
     */
    public function resetForManualRetry(int $id, int $now): bool
    {
        global $wpdb;

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table() . "
             SET status = %s, attempts = 0, next_retry_at = NULL, error_message = NULL,
                 claim_token = NULL, locked_until = NULL, updated_at = %s
             WHERE id = %d AND status IN (%s, %s)",
            self::STATUS_PENDING,
            self::ts($now),
            $id,
            self::STATUS_ERROR,
            self::STATUS_NOT_FOUND
        ));

        return $affected === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return array{pending: int, processing: int, done: int, not_found: int, error: int, total: int}
     */
    public function getCounts(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM " . self::table() . " GROUP BY status",
            ARRAY_A
        );

        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_DONE => 0,
            self::STATUS_NOT_FOUND => 0,
            self::STATUS_ERROR => 0,
        ];

        foreach ($rows ?: [] as $row) {
            $counts[$row['status']] = (int) $row['c'];
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 50): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM " . self::table() . " ORDER BY updated_at DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Completed geocodes with coordinates, for the map.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMapPoints(int $limit = 500): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, lat, lng FROM " . self::table() . "
                 WHERE status = %s AND lat IS NOT NULL AND lng IS NOT NULL
                 ORDER BY updated_at DESC LIMIT %d",
                self::STATUS_DONE,
                $limit
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function finish(int $id, string $token, int $now, array $fields): bool
    {
        global $wpdb;

        $fields['claim_token'] = null;
        $fields['locked_until'] = null;
        $fields['updated_at'] = self::ts($now);

        $affected = $wpdb->update(self::table(), $fields, [
            'id' => $id,
            'claim_token' => $token,
            'status' => self::STATUS_PROCESSING,
        ]);

        return $affected === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRowByPost(int $postId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, address_hash FROM " . self::table() . " WHERE post_id = %d", $postId),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function claimable(string $ts, int $maxAttempts): array
    {
        return [
            "((status = %s AND (next_retry_at IS NULL OR next_retry_at <= %s) AND attempts < %d)
              OR (status = %s AND locked_until < %s AND attempts < %d))",
            [self::STATUS_PENDING, $ts, $maxAttempts, self::STATUS_PROCESSING, $ts, $maxAttempts],
        ];
    }

    private static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'gma_geo_jobs';
    }

    private static function ts(int $unixTime): string
    {
        return gmdate('Y-m-d H:i:s', $unixTime);
    }
}
