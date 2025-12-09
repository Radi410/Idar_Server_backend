<?php
// /recordings/index.php

// Security and caching headers
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");

// Configuration
$recording_dir = __DIR__;
$expected_file_size_min = 100 * 1024 * 1024; // 100MB minimum (suspicious if smaller)
$expected_file_size_max = 1500 * 1024 * 1024; // 1.5GB maximum (suspicious if larger)

// Security: Sanitize and validate filenames
function sanitizeFilename($filename) {
    $basename = basename($filename);
    // Only allow format: YYYY-MM-DD_HHMM.wav
    if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{4}\.wav$/', $basename)) {
        return null;
    }
    return $basename;
}

// Check directory access
if (!is_readable($recording_dir)) {
    http_response_code(500);
    die("
    <!DOCTYPE html>
    <html lang='no'>
    <head>
        <meta charset='UTF-8'>
        <title>Feil - Idar Opptak</title>
        <style>
            body { font-family: sans-serif; text-align: center; padding: 50px; }
            .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; 
                     padding: 20px; border-radius: 5px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class='error'>
            <h1>⚠️ Feil</h1>
            <p>Kan ikke lese opptaksmappen. Kontakt IT-avdelingen.</p>
        </div>
    </body>
    </html>
    ");
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function getTimeSlot($hour) {
    $start = sprintf('%02d', $hour);
    $end = sprintf('%02d', ($hour + 2) % 24);
    return "$start:00-$end:00";
}

function checkFileHealth($size, $min, $max) {
    if ($size < $min) return 'small';
    if ($size > $max) return 'large';
    return 'ok';
}

// Get all recordings
$files = glob($recording_dir . "/*.wav");
$grouped_files = [];
$total_size = 0;

foreach ($files as $file) {
    $filename = basename($file);
    $safe_filename = sanitizeFilename($filename);
    
    if (!$safe_filename) continue; // Skip invalid filenames
    
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})\d{2}\.wav$/', $safe_filename, $matches)) {
        $date = $matches[1];
        $hour = intval($matches[2]);
        $size = filesize($file);
        $total_size += $size;
        
        $grouped_files[$date][] = [
            'filename' => $safe_filename,
            'timeslot' => getTimeSlot($hour),
            'size' => $size,
            'modified' => filemtime($file),
            'health' => checkFileHealth($size, $expected_file_size_min, $expected_file_size_max)
        ];
    }
}

// Sort by date (newest first)
krsort($grouped_files);

// Sort recordings within each date by timeslot
foreach ($grouped_files as &$recordings) {
    usort($recordings, function($a, $b) {
        return strcmp($a['timeslot'], $b['timeslot']);
    });
}
unset($recordings);

$file_count = array_sum(array_map('count', $grouped_files));
?>
<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="300"> <!-- Auto-refresh every 5 minutes -->
    <title>Idar Opptak - Radio Nova</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="container">
            <h1>📻 Idar Opptak</h1>
            <p>Automatiske opptak fra Radio Nova</p>
            <div class="stats">
                <span><?php echo $file_count; ?> opptak</span>
                <span><?php echo formatBytes($total_size); ?> totalt</span>
                <span class="auto-refresh">🔄 Oppdateres automatisk</span>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="info-box">
            <p>📌 Opptakene lagres i 2-timers intervaller fra <strong>06:00 til 24:00</strong>. 
            Filene beholdes i <strong>48 timer</strong> før de slettes automatisk.</p>
        </div>

        <?php if (empty($grouped_files)): ?>
            <div class="empty-state">
                <p>⚠️ Ingen opptak funnet.</p>
                <p class="muted">Sjekk at opptakssystemet kjører.</p>
            </div>
        <?php else: ?>
            <?php foreach ($grouped_files as $date => $recordings): ?>
                <section class="date-section">
                    <h2>
                        <?php 
                        $dateObj = new DateTime($date);
                        $norwegianDays = ['søndag', 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag'];
                        $dayName = $norwegianDays[$dateObj->format('w')];
                        echo $dateObj->format('d.m.Y') . ' (' . ucfirst($dayName) . ')'; 
                        ?>
                    </h2>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tidspunkt</th>
                                    <th>Filnavn</th>
                                    <th>Størrelse</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recordings as $recording): ?>
                                    <tr class="<?php echo $recording['health'] !== 'ok' ? 'warning' : ''; ?>">
                                        <td class="timeslot" data-label="Tidspunkt">
                                            <span class="time-badge"><?php echo htmlspecialchars($recording['timeslot']); ?></span>
                                        </td>
                                        <td class="filename" data-label="Filnavn">
                                            <?php echo htmlspecialchars($recording['filename']); ?>
                                        </td>
                                        <td class="filesize" data-label="Størrelse">
                                            <?php echo formatBytes($recording['size']); ?>
                                        </td>
                                        <td class="status" data-label="Status">
                                            <?php if ($recording['health'] === 'small'): ?>
                                                <span class="status-badge warning" title="Filen er mistenkelig liten">⚠️ Liten</span>
                                            <?php elseif ($recording['health'] === 'large'): ?>
                                                <span class="status-badge warning" title="Filen er mistenkelig stor">⚠️ Stor</span>
                                            <?php else: ?>
                                                <span class="status-badge ok">✓ OK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="download" data-label="">
                                            <a href="<?php echo htmlspecialchars($recording['filename']); ?>" 
                                               class="download-btn" 
                                               download
                                               title="Last ned <?php echo htmlspecialchars($recording['filename']); ?>">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                                    <polyline points="7 10 12 15 17 10"></polyline>
                                                    <line x1="12" y1="15" x2="12" y2="3"></line>
                                                </svg>
                                                Last ned
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <footer>
        <div class="container">
            <p>Radio Nova - Teknisk avdeling</p>
            <p class="muted">Siden oppdateres automatisk hvert 5. minutt</p>
        </div>
    </footer>

    <script>
        // Visual countdown for next refresh
        let secondsLeft = 300;
        const updateCountdown = () => {
            secondsLeft--;
            if (secondsLeft <= 0) return;
            
            const minutes = Math.floor(secondsLeft / 60);
            const seconds = secondsLeft % 60;
            const refreshEl = document.querySelector('.auto-refresh');
            if (refreshEl) {
                refreshEl.textContent = `🔄 Oppdateres om ${minutes}:${seconds.toString().padStart(2, '0')}`;
            }
        };
        setInterval(updateCountdown, 1000);
    </script>
</body>
</html>
