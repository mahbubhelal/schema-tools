<?php

declare(strict_types=1);

namespace Mahbub\SchemaTools\Tests\Fixtures\DetectMySql\Queries\Sugar;

/**
 * Names its connection through a property rather than DB::connection(), lives
 * in a subdirectory, and carries the SQL shapes the detector must see through:
 * comments, a common table expression, backticks and a derived table.
 */
final class ProductsQuery
{
    private string $connection = 'sugar';

    public function connection(): string
    {
        return $this->connection;
    }

    public function sql(): string
    {
        return <<<'SQL'
            -- a comment that mentions FROM nowhere
            /* and a block comment JOIN elsewhere */
            WITH changed_users AS (
                SELECT su.id FROM `users` su
            )
            SELECT c.id
            FROM `contacts` c
            JOIN accounts_contacts ac ON ac.contact_id = c.id
            LEFT JOIN changed_users cu ON cu.id = ac.user_id
            LEFT JOIN (SELECT id FROM accounts) a ON a.id = ac.account_id
            WHERE c.deleted = 0
            SQL;
    }
}
