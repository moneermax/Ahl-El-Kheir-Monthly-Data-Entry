<?php
declare(strict_types=1);

/**
 * One-time October 2026 workbook importer for Ahl El Kheir Monthly Data Entry.
 *
 * Safe by default: without --import, the script performs a transaction and rolls it back.
 * No schema changes or DDL are executed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

$doImport = in_array('--import', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
if ($doImport && $dryRun) {
    fwrite(STDERR, "Choose either --dry-run or --import, not both.\n");
    exit(2);
}
if (!$doImport) {
    $dryRun = true;
}

$root = __DIR__;
$configPath = $root . '/config/database.php';
$dataPath = $root . '/october-2026-import.json';

if (!is_file($configPath) || !is_file($dataPath)) {
    fwrite(STDERR, "Missing config/database.php or october-2026-import.json. Place both files in the project root.\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "PHP extension pdo_mysql is required.\n");
    exit(2);
}

try {
    $config = require $configPath;
    if (!is_array($config) || ($config['database'] ?? '') !== 'ahl_el_kheir_monthly_data') {
        throw new RuntimeException(
            "Safety stop: the configured database is not ahl_el_kheir_monthly_data. No changes were made."
        );
    }

    $payload = json_decode(
        (string) file_get_contents($dataPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $monthlyRows = $payload['monthly_entries'] ?? null;
    $departureRows = $payload['departures'] ?? null;
    if (!is_array($monthlyRows) || !is_array($departureRows)) {
        throw new RuntimeException("The import JSON is missing its monthly_entries or departures data.");
    }
    if (($payload['metadata']['report_month'] ?? '') !== '2026-10-01') {
        throw new RuntimeException("The import file is not for October 2026.");
    }

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        ),
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Verify the expected installed schema; never create or alter anything here.
    $schemaVersion = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM mde_schema_version')->fetchColumn();
    if ($schemaVersion < 6) {
        throw new RuntimeException(
            "Database schema version {$schemaVersion} detected; version 6 is required. Apply the existing migrations first."
        );
    }
    foreach ([
        'mde_sponsors',
        'mde_mothers',
        'mde_orphans',
        'mde_sponsorships',
        'mde_monthly_sponsorship_entries',
        'mde_monthly_other_donations',
        'mde_sponsor_departures',
    ] as $table) {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
    }

    $pdo->beginTransaction();

    $counts = [
        'sponsors_created' => 0,
        'sponsors_reused' => 0,
        'mothers_created' => 0,
        'mothers_reused' => 0,
        'orphans_created' => 0,
        'orphans_reused' => 0,
        'relationships_created' => 0,
        'relationships_reused' => 0,
        'monthly_entries' => 0,
        'donations' => 0,
        'departures' => 0,
    ];

    $sponsorCache = [];
    $motherCache = [];
    $orphanCache = [];

    $findSponsor = $pdo->prepare(
        'SELECT id FROM mde_sponsors
         WHERE sponsor_name = ? AND sponsor_type = ? AND file_number <=> ?
         ORDER BY id LIMIT 2 FOR UPDATE'
    );
    $insertSponsor = $pdo->prepare(
        'INSERT INTO mde_sponsors (sponsor_name, file_number, sponsor_type, is_active)
         VALUES (?, ?, ?, ?)'
    );
    $updateSponsor = $pdo->prepare(
        'UPDATE mde_sponsors SET is_active = ? WHERE id = ?'
    );

    $getSponsorId = static function (
        string $name,
        string $type,
        ?string $fileNumber,
        bool $active
    ) use (&$sponsorCache, &$counts, $findSponsor, $insertSponsor, $updateSponsor): int {
        $key = json_encode([$name, $type, $fileNumber], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (isset($sponsorCache[$key])) {
            $id = $sponsorCache[$key];
            $updateSponsor->execute([$active ? 1 : 0, $id]);
            return $id;
        }

        $findSponsor->execute([$name, $type, $fileNumber]);
        $matches = $findSponsor->fetchAll();
        if (count($matches) > 1) {
            throw new RuntimeException("More than one sponsor master matches: {$name} / {$type} / " . ($fileNumber ?? 'NULL'));
        }
        if ($matches) {
            $id = (int) $matches[0]['id'];
            $counts['sponsors_reused']++;
            $updateSponsor->execute([$active ? 1 : 0, $id]);
        } else {
            $insertSponsor->execute([$name, $fileNumber, $type, $active ? 1 : 0]);
            $id = (int) $GLOBALS['pdo']->lastInsertId();
            $counts['sponsors_created']++;
        }
        $sponsorCache[$key] = $id;
        return $id;
    };

    // Make PDO available to the closure without relying on global state elsewhere.
    $GLOBALS['pdo'] = $pdo;

    $findMother = $pdo->prepare(
        'SELECT id FROM mde_mothers WHERE mother_name = ? ORDER BY id LIMIT 2 FOR UPDATE'
    );
    $insertMother = $pdo->prepare('INSERT INTO mde_mothers (mother_name) VALUES (?)');

    $getMotherId = static function (string $name) use (&$motherCache, &$counts, $findMother, $insertMother, $pdo): int {
        if (isset($motherCache[$name])) {
            return $motherCache[$name];
        }
        $findMother->execute([$name]);
        $matches = $findMother->fetchAll();
        if (count($matches) > 1) {
            throw new RuntimeException("More than one mother master matches: {$name}");
        }
        if ($matches) {
            $id = (int) $matches[0]['id'];
            $counts['mothers_reused']++;
        } else {
            $insertMother->execute([$name]);
            $id = (int) $pdo->lastInsertId();
            $counts['mothers_created']++;
        }
        $motherCache[$name] = $id;
        return $id;
    };

    $findOrphan = $pdo->prepare(
        'SELECT id FROM mde_orphans WHERE mother_id = ? AND orphan_name = ? ORDER BY id LIMIT 2 FOR UPDATE'
    );
    $insertOrphan = $pdo->prepare('INSERT INTO mde_orphans (mother_id, orphan_name) VALUES (?, ?)');

    $getOrphanId = static function (int $motherId, string $name) use (&$orphanCache, &$counts, $findOrphan, $insertOrphan, $pdo): int {
        $key = $motherId . "\0" . $name;
        if (isset($orphanCache[$key])) {
            return $orphanCache[$key];
        }
        $findOrphan->execute([$motherId, $name]);
        $matches = $findOrphan->fetchAll();
        if (count($matches) > 1) {
            throw new RuntimeException("More than one orphan master matches under mother ID {$motherId}: {$name}");
        }
        if ($matches) {
            $id = (int) $matches[0]['id'];
            $counts['orphans_reused']++;
        } else {
            $insertOrphan->execute([$motherId, $name]);
            $id = (int) $pdo->lastInsertId();
            $counts['orphans_created']++;
        }
        $orphanCache[$key] = $id;
        return $id;
    };

    $findRelationships = $pdo->prepare(
        'SELECT id, status FROM mde_sponsorships
         WHERE sponsor_id = ? AND orphan_id = ? ORDER BY id FOR UPDATE'
    );
    $findMonthly = $pdo->prepare(
        'SELECT id FROM mde_monthly_sponsorship_entries
         WHERE sponsorship_id = ? AND report_month = ? LIMIT 1 FOR UPDATE'
    );
    $insertRelationship = $pdo->prepare(
        "INSERT INTO mde_sponsorships (sponsor_id, orphan_id, start_date, status)
         VALUES (?, ?, ?, 'active')"
    );
    $insertMonthly = $pdo->prepare(
        'INSERT INTO mde_monthly_sponsorship_entries
         (sponsorship_id, report_month, entry_date, sponsorship_amount, services_amount,
          entered_by, supervisor_name, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertDonation = $pdo->prepare(
        'INSERT INTO mde_monthly_other_donations
         (sponsor_id, sponsorship_id, report_month, donation_date, donation_type, amount, entered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($monthlyRows as $index => $row) {
        $sponsorName = trim((string) ($row['sponsor_name'] ?? ''));
        $sponsorType = (string) ($row['sponsor_type'] ?? 'individual');
        $fileNumber = isset($row['file_number']) && $row['file_number'] !== '' ? (string) $row['file_number'] : null;
        $entryDate = (string) ($row['entry_date'] ?? '');
        $reportMonth = (string) ($row['report_month'] ?? '');
        $motherName = trim((string) ($row['mother_name'] ?? ''));
        $orphanName = trim((string) ($row['orphan_name'] ?? ''));
        $amount = filter_var($row['sponsorship_amount'] ?? null, FILTER_VALIDATE_INT);
        $services = filter_var($row['services_amount'] ?? null, FILTER_VALIDATE_INT);

        if ($sponsorName === '' || $motherName === '' || $orphanName === '' ||
            !in_array($sponsorType, ['individual', 'tree'], true) ||
            $reportMonth !== '2026-10-01' ||
            !preg_match('/^2026-10-\d{2}$/', $entryDate) ||
            $amount === false || $amount === null || $amount < 0 ||
            $services === false || $services === null || $services < 0) {
            throw new RuntimeException("Invalid monthly row at JSON index {$index} (source Excel row " . ($row['source_row'] ?? '?') . ").");
        }

        $sponsorId = $getSponsorId($sponsorName, $sponsorType, $fileNumber, true);
        $motherId = $getMotherId($motherName);
        $orphanId = $getOrphanId($motherId, $orphanName);

        $findRelationships->execute([$sponsorId, $orphanId]);
        $links = $findRelationships->fetchAll();
        $activeLinks = array_values(array_filter($links, static fn(array $link): bool => $link['status'] === 'active'));
        $pausedLinks = array_values(array_filter($links, static fn(array $link): bool => $link['status'] === 'paused'));

        if (count($activeLinks) > 1) {
            throw new RuntimeException("Duplicate active sponsor/orphan relationships: {$sponsorName} / {$orphanName}");
        }
        if ($pausedLinks) {
            throw new RuntimeException("A paused relationship exists and needs review: {$sponsorName} / {$orphanName}");
        }

        if ($activeLinks) {
            $relationshipId = (int) $activeLinks[0]['id'];
            $findMonthly->execute([$relationshipId, $reportMonth]);
            if ($findMonthly->fetch()) {
                throw new RuntimeException("October 2026 entry already exists: {$sponsorName} / {$orphanName}. Import stopped to prevent duplicates.");
            }
            $counts['relationships_reused']++;
        } else {
            $insertRelationship->execute([$sponsorId, $orphanId, $entryDate]);
            $relationshipId = (int) $pdo->lastInsertId();
            $counts['relationships_created']++;
        }

        $insertMonthly->execute([
            $relationshipId,
            $reportMonth,
            $entryDate,
            $amount,
            $services,
            trim((string) ($row['entered_by'] ?? '')) ?: null,
            trim((string) ($row['supervisor_name'] ?? '')) ?: null,
            trim((string) ($row['notes'] ?? '')) ?: null,
        ]);
        $counts['monthly_entries']++;

        $donations = $row['donations'] ?? [];
        if (!is_array($donations)) {
            throw new RuntimeException("Invalid donation list for {$sponsorName} / {$orphanName}");
        }
        foreach ($donations as $donation) {
            $type = trim((string) ($donation['type'] ?? ''));
            $donationAmount = filter_var($donation['amount'] ?? null, FILTER_VALIDATE_INT);
            if ($type === '' || $donationAmount === false || $donationAmount === null || $donationAmount < 0) {
                throw new RuntimeException("Invalid donation for {$sponsorName} / {$orphanName}");
            }
            $insertDonation->execute([
                $sponsorId,
                $relationshipId,
                $reportMonth,
                $entryDate,
                $type,
                $donationAmount,
                trim((string) ($row['entered_by'] ?? '')) ?: null,
            ]);
            $counts['donations']++;
        }
    }

    $insertDeparture = $pdo->prepare(
        'INSERT INTO mde_sponsor_departures
         (sponsor_id, file_number_snapshot, departure_date, last_paid_month,
          orphan_count_snapshot, sponsorship_amount_snapshot, services_amount_snapshot,
          entered_by, supervisor_name, reason, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $findDuplicateDeparture = $pdo->prepare(
        'SELECT id FROM mde_sponsor_departures
         WHERE sponsor_id = ?
           AND file_number_snapshot <=> ?
           AND departure_date = ?
           AND last_paid_month <=> ?
           AND orphan_count_snapshot <=> ?
           AND sponsorship_amount_snapshot = ?
           AND services_amount_snapshot = ?
           AND notes <=> ?
         LIMIT 1 FOR UPDATE'
    );
    $endRelationships = $pdo->prepare(
        "UPDATE mde_sponsorships
         SET status = 'ended', end_date = ?
         WHERE sponsor_id = ? AND status = 'active'"
    );

    foreach ($departureRows as $index => $row) {
        $sponsorName = trim((string) ($row['sponsor_name'] ?? ''));
        $sponsorType = (string) ($row['sponsor_type'] ?? 'individual');
        $fileNumber = isset($row['file_number']) && $row['file_number'] !== '' ? (string) $row['file_number'] : null;
        $departureDate = (string) ($row['departure_date'] ?? '');
        $lastPaidMonth = isset($row['last_paid_month']) && $row['last_paid_month'] !== '' ? (string) $row['last_paid_month'] : null;
        $orphanCount = $row['orphan_count_snapshot'] ?? null;
        $amount = filter_var($row['sponsorship_amount_snapshot'] ?? null, FILTER_VALIDATE_INT);
        $services = filter_var($row['services_amount_snapshot'] ?? null, FILTER_VALIDATE_INT);
        $notes = trim((string) ($row['notes'] ?? '')) ?: null;

        if ($sponsorName === '' || !in_array($sponsorType, ['individual', 'tree'], true) ||
            !preg_match('/^2026-10-\d{2}$/', $departureDate) ||
            ($lastPaidMonth !== null && !preg_match('/^2026-(0[1-9]|1[0-2])-01$/', $lastPaidMonth)) ||
            ($orphanCount !== null && (!is_numeric($orphanCount) || (int) $orphanCount < 0)) ||
            $amount === false || $amount === null || $amount < 0 ||
            $services === false || $services === null || $services < 0) {
            throw new RuntimeException("Invalid departure row at JSON index {$index} (source Excel row " . ($row['source_row'] ?? '?') . ").");
        }

        $sponsorId = $getSponsorId($sponsorName, $sponsorType, $fileNumber, false);
        $findDuplicateDeparture->execute([
            $sponsorId,
            $fileNumber,
            $departureDate,
            $lastPaidMonth,
            $orphanCount,
            $amount,
            $services,
            $notes,
        ]);
        if ($findDuplicateDeparture->fetch()) {
            throw new RuntimeException("This departure already exists: {$sponsorName}. Import stopped to prevent duplicates.");
        }

        $endRelationships->execute([$departureDate, $sponsorId]);
        $insertDeparture->execute([
            $sponsorId,
            $fileNumber,
            $departureDate,
            $lastPaidMonth,
            $orphanCount,
            $amount,
            $services,
            trim((string) ($row['entered_by'] ?? '')) ?: null,
            trim((string) ($row['supervisor_name'] ?? '')) ?: null,
            trim((string) ($row['reason'] ?? '')) ?: null,
            $notes,
        ]);
        $counts['departures']++;
    }

    if ($dryRun) {
        $pdo->rollBack();
        echo "DRY RUN PASSED — all rows validated; transaction rolled back, no database changes made.\n";
    } else {
        $pdo->commit();
        echo "IMPORT COMMITTED successfully.\n";
    }

    foreach ($counts as $label => $count) {
        echo str_pad($label, 28) . ": {$count}\n";
    }
    echo "Monthly rows prepared       : " . count($monthlyRows) . "\n";
    echo "Departure rows prepared     : " . count($departureRows) . "\n";

    if (!empty($payload['metadata']['warnings'])) {
        echo "\nIMPORTANT SOURCE NOTES:\n";
        foreach ($payload['metadata']['warnings'] as $warning) {
            echo "- {$warning}\n";
        }
    }

    if ($dryRun) {
        echo "\nIf the counts and warnings are expected, run the same command with --import to commit the transaction.\n";
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "IMPORT STOPPED — no partial transaction was committed.\n");
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
?>
