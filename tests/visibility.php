<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/auth.php';

// Run with: php tests/visibility.php (requires pdo_sqlite; no application DB).
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE administrative_units (id INTEGER PRIMARY KEY, parent_id INTEGER)');
$pdo->exec('INSERT INTO administrative_units VALUES (1, NULL), (2, 1), (3, NULL)');
$pdo->exec('CREATE TABLE resources (id INTEGER PRIMARY KEY, administrative_unit_id INTEGER, county TEXT, status TEXT)');
$pdo->exec("INSERT INTO resources VALUES
    (1, 1, 'Nairobi', 'active'), (2, 2, 'Nairobi', 'active'),
    (3, 3, 'Nairobi', 'active'), (4, NULL, 'Nairobi', 'active'),
    (5, NULL, 'Kisumu', 'active'), (6, 2, 'Nairobi', 'paused'),
    (7, NULL, 'Nairobi', 'paused')");
$checks = 0;
function expect_ids(PDO $pdo, array $user, array $expected, string $label, string $suffix = '', array $params = []): void
{
    global $checks;
    $clause = visibility_clause($pdo, $user, 'administrative_unit_id', 'county', $params);
    // Native MySQL prepares require each named placeholder to occur once.
    preg_match_all('/:[a-z_][a-z_0-9]*/i', $clause . $suffix, $matches);
    if (count($matches[0]) !== count(array_unique($matches[0]))) {
        throw new RuntimeException($label . ': repeated named placeholder');
    }
    $query = $pdo->prepare("SELECT id FROM resources WHERE status = 'active' AND " . $clause . $suffix . ' ORDER BY id');
    $query->execute($params);
    $actual = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': ' . json_encode($actual));
    }
    $checks++;
}
$user = ['role' => 'operator', 'county' => 'Nairobi', 'administrative_unit_id' => null];
expect_ids($pdo, $user, [1, 2, 3, 4], 'County scope preserves preceding status filter');
expect_ids($pdo, $user, [], 'County scope preserves following filter', ' AND county = :requested_county', ['requested_county' => 'Kisumu']);
$user['administrative_unit_id'] = 1;
expect_ids($pdo, $user, [1, 2, 4], 'Assigned unit and descendants, plus legacy county records');
$user['administrative_unit_id'] = 999;
expect_ids($pdo, $user, [], 'Deleted or invalid scope denies access', ' AND county = :requested_county', ['requested_county' => 'Nairobi']);
$pdo->exec('DROP TABLE administrative_units');
$user['administrative_unit_id'] = 1;
expect_ids($pdo, $user, [], 'Hierarchy query failure denies access');
$user['role'] = 'admin';
expect_ids($pdo, $user, [1, 2, 3, 4, 5], 'Admin scope remains unrestricted');
echo "$checks visibility regression checks passed.\n";
