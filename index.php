<?php
declare(strict_types=1);

/**
 * PARALLEL — Single-File Deployable Web Game for Hostinger
 * Upload this single file as "index.php" into public_html/ (or public_html/play/).
 */

// =====================================================================
// 1. CONFIGURATION (Edit these 3 lines whenever needed)
// =====================================================================
define('ADSENSE_PUB_ID', ''); // Example when approved: 'ca-pub-1234567890123456'
define('CONTACT_EMAIL', 'support@yourdomain.com');
define('APP_TIMEZONE', 'Asia/Karachi');

date_default_timezone_set(APP_TIMEZONE);

// =====================================================================
// 2. AUTOMATIC SQLITE DATABASE & SECURITY SETUP
// =====================================================================
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$htaccessFile = $dataDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    file_put_contents(
        $htaccessFile,
        "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
    );
}

try {
    $pdo = new PDO('sqlite:' . $dataDir . '/parallel.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
} catch (Throwable $e) {
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database initialization failed. Check PHP PDO SQLite extension in Hostinger hPanel.']);
        exit;
    }
    die('Database initialization failed. Ensure PDO SQLite is enabled in Hostinger PHP Extensions.');
}

$pdo->exec("
CREATE TABLE IF NOT EXISTS rooms (
    code TEXT PRIMARY KEY,
    player_a_token TEXT NOT NULL,
    player_a_name TEXT NOT NULL,
    player_b_token TEXT DEFAULT NULL,
    player_b_name TEXT DEFAULT NULL,
    streak INTEGER NOT NULL DEFAULT 0,
    broken_streak INTEGER NOT NULL DEFAULT 0,
    last_completed_day INTEGER NOT NULL DEFAULT 0,
    last_restore_day INTEGER NOT NULL DEFAULT 0,
    active_day INTEGER NOT NULL DEFAULT 0,
    turn_player TEXT NOT NULL DEFAULT 'A',
    moves_left INTEGER NOT NULL DEFAULT 3,
    placed_json TEXT NOT NULL DEFAULT '{}',
    revealed_json TEXT NOT NULL DEFAULT '[]',
    ping_json TEXT DEFAULT NULL,
    puzzle_solved INTEGER NOT NULL DEFAULT 0,
    spark_a INTEGER DEFAULT NULL,
    spark_b INTEGER DEFAULT NULL,
    created_at INTEGER NOT NULL
);
");

// =====================================================================
// 3. BACKEND HELPERS & GAME LOGIC
// =====================================================================
function currentDayIndex(): int {
    $dt = new DateTimeImmutable('today');
    return (int) floor(($dt->getTimestamp() + $dt->getOffset()) / 86400);
}

function secondsUntilMidnight(): int {
    $now = new DateTimeImmutable('now');
    $tomorrow = new DateTimeImmutable('tomorrow');
    return max(0, $tomorrow->getTimestamp() - $now->getTimestamp());
}

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function cleanName(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $name = mb_substr($name, 0, 18);
    return $name !== '' ? $name : 'Player';
}

function cleanToken(string $token): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', substr($token, 0, 64));
}

function cleanCode(string $code): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', substr($code, 0, 8)));
}

function generateRoomCode(PDO $pdo): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not allocate room code.');
}

function getSparkPrompt(int $day, string $code): array {
    $prompts = [
        ['q' => 'What brings more peace to your evening?', 'a' => 'A quiet walk outdoors', 'b' => 'Warm tea & total silence'],
        ['q' => 'Which habit helps you reset fastest after a hard day?', 'a' => 'Tidying one small space', 'b' => 'Disconnecting from screens'],
        ['q' => 'What kind of reminder helps you most right now?', 'a' => 'Slow down, progress takes time', 'b' => 'Focus on what is in your control'],
        ['q' => 'Which season feels closest to your personality?', 'a' => 'Crisp early winter morning', 'b' => 'Gentle rainy afternoon'],
        ['q' => 'When starting something important, you prefer:', 'a' => 'Planning every detail first', 'b' => 'Taking the first small step'],
        ['q' => 'What makes a conversation feel truly meaningful?', 'a' => 'Being listened to patiently', 'b' => 'Sharing honest gratitude'],
        ['q' => 'If you had one extra quiet hour today, you would:', 'a' => 'Read or reflect calmly', 'b' => 'Rest without any guilt'],
        ['q' => 'Which simple blessing felt easiest to overlook this week?', 'a' => 'Good health & steady energy', 'b' => 'Safety & a peaceful home'],
        ['q' => 'How do you handle a stressful deadline?', 'a' => 'Break it into tiny tasks', 'b' => 'Tackle the hardest part first'],
        ['q' => 'Which sound feels most calming?', 'a' => 'Steady rain on a window', 'b' => 'Early morning birds'],
        ['q' => 'What kind of effort matters most over a year?', 'a' => 'Tiny daily consistency', 'b' => 'Clear boundaries with time'],
        ['q' => 'At the end of a good day, you feel best when:', 'a' => 'You helped someone quietly', 'b' => 'You kept a promise to yourself'],
        ['q' => 'Which environment helps you think clearest?', 'a' => 'Clean desk, dim warm light', 'b' => 'Fresh air near a window'],
        ['q' => 'What do you value more in close friends or family?', 'a' => 'Reliability in hard moments', 'b' => 'Calmness in everyday life'],
    ];
    $seed = abs(crc32($code . '-spark-' . $day));
    return $prompts[$seed % count($prompts)];
}

function deterministicRand(int &$seed, int $min, int $max): int {
    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
    return $min + ($seed % ($max - $min + 1));
}

function generateDailyPuzzle(string $code, int $day): array {
    $seed = abs(crc32($code . '-grid-' . $day));
    if ($seed === 0) $seed = 98765;

    $startR = deterministicRand($seed, 0, 4);
    $endR = deterministicRand($seed, 0, 4);

    $r = $startR;
    $c = 0;
    $path = ["$r,$c" => true];
    $midTargetRow = ($startR <= 2) ? 4 : 0;

    $c = 1;
    $path["$r,$c"] = true;
    while ($r !== $midTargetRow) {
        $r += ($midTargetRow > $r) ? 1 : -1;
        $path["$r,$c"] = true;
    }

    $c = 2;
    $path["$r,$c"] = true;
    $midRow2 = deterministicRand($seed, 0, 4);
    while ($r !== $midRow2) {
        $r += ($midRow2 > $r) ? 1 : -1;
        $path["$r,$c"] = true;
    }

    $c = 3;
    $path["$r,$c"] = true;
    while ($r !== $endR) {
        $r += ($endR > $r) ? 1 : -1;
        $path["$r,$c"] = true;
    }

    $c = 4;
    $path["$r,$c"] = true;

    $freeCells = [];
    for ($rr = 0; $rr < 5; $rr++) {
        for ($cc = 0; $cc < 5; $cc++) {
            $key = "$rr,$cc";
            if (!isset($path[$key])) {
                $freeCells[] = $key;
            }
        }
    }

    for ($i = count($freeCells) - 1; $i > 0; $i--) {
        $j = deterministicRand($seed, 0, $i);
        [$freeCells[$i], $freeCells[$j]] = [$freeCells[$j], $freeCells[$i]];
    }

    return [
        'start' => "$startR,0",
        'end' => "$endR,4",
        'blockersA' => array_slice($freeCells, 0, 3),
        'blockersB' => array_slice($freeCells, 3, 3),
    ];
}

function checkBfsSolved(array $puzzle, array $placed): bool {
    $passable = [$puzzle['start'] => true, $puzzle['end'] => true];
    foreach ($placed as $k => $_owner) {
        $passable[$k] = true;
    }

    $queue = [$puzzle['start']];
    $visited = [$puzzle['start'] => true];
    $dirs = [[1,0], [-1,0], [0,1], [0,-1]];

    while (!empty($queue)) {
        $curr = array_shift($queue);
        if ($curr === $puzzle['end']) return true;
        [$r, $c] = array_map('intval', explode(',', $curr));
        foreach ($dirs as [$dr, $dc]) {
            $nr = $r + $dr;
            $nc = $c + $dc;
            if ($nr < 0 || $nr > 4 || $nc < 0 || $nc > 4) continue;
            $nk = "$nr,$nc";
            if (isset($passable[$nk]) && !isset($visited[$nk])) {
                $visited[$nk] = true;
                $queue[] = $nk;
            }
        }
    }
    return false;
}

function syncRoomDayAndStreak(PDO $pdo, array $room): array {
    $today = currentDayIndex();

    // Break streak back to 0 if yesterday was missed (Snapchat pattern)
    if ((int)$room['streak'] > 0 && (int)$room['last_completed_day'] < ($today - 1)) {
        $broken = (int)$room['streak'];
        $stmt = $pdo->prepare("UPDATE rooms SET broken_streak = ?, streak = 0 WHERE code = ?");
        $stmt->execute([$broken, $room['code']]);
        $room['broken_streak'] = $broken;
        $room['streak'] = 0;
    }

    // Expire restore eligibility after 1 missed cycle
    if ((int)$room['broken_streak'] > 0 && (int)$room['last_completed_day'] < ($today - 2)) {
        $stmt = $pdo->prepare("UPDATE rooms SET broken_streak = 0 WHERE code = ?");
        $stmt->execute([$room['code']]);
        $room['broken_streak'] = 0;
    }

    // Refresh board at midnight
    if ((int)$room['active_day'] !== $today) {
        $starter = ($today % 2 === 0) ? 'A' : 'B';
        if (empty($room['player_b_token'])) $starter = 'A';

        $stmt = $pdo->prepare("
            UPDATE rooms
            SET active_day = ?, turn_player = ?, moves_left = 3,
                placed_json = '{}', revealed_json = '[]', ping_json = NULL,
                puzzle_solved = 0, spark_a = NULL, spark_b = NULL
            WHERE code = ?
        ");
        $stmt->execute([$today, $starter, $room['code']]);
        $room['active_day'] = $today;
        $room['turn_player'] = $starter;
        $room['moves_left'] = 3;
        $room['placed_json'] = '{}';
        $room['revealed_json'] = '[]';
        $room['ping_json'] = null;
        $room['puzzle_solved'] = 0;
        $room['spark_a'] = null;
        $room['spark_b'] = null;
    }

    return $room;
}

function buildRoomPayload(array $room, string $role): array {
    $today = currentDayIndex();
    $puzzle = generateDailyPuzzle($room['code'], $today);
    $spark = getSparkPrompt($today, $room['code']);

    $placed = json_decode($room['placed_json'], true) ?: [];
    $revealed = json_decode($room['revealed_json'], true) ?: [];
    $ping = $room['ping_json'] ? json_decode($room['ping_json'], true) : null;

    $myBlockers = ($role === 'A') ? $puzzle['blockersA'] : $puzzle['blockersB'];
    $completedToday = ((int)$room['last_completed_day'] === $today);
    $canRestore = ((int)$room['broken_streak'] > 0)
        && ((int)$room['last_completed_day'] === ($today - 2))
        && ((int)$room['last_restore_day'] <= ($today - 7));

    $sparkA = $room['spark_a'] !== null ? (int)$room['spark_a'] : null;
    $sparkB = $room['spark_b'] !== null ? (int)$room['spark_b'] : null;
    $bothAnswered = ($sparkA !== null && $sparkB !== null);

    return [
        'code' => $room['code'],
        'role' => $role,
        'player_a_name' => $room['player_a_name'],
        'player_b_name' => $room['player_b_name'],
        'partner_joined' => !empty($room['player_b_token']),
        'streak' => (int)$room['streak'],
        'broken_streak' => (int)$room['broken_streak'],
        'can_restore' => $canRestore,
        'completed_today' => $completedToday,
        'seconds_left' => secondsUntilMidnight(),
        'turn_player' => $room['turn_player'],
        'moves_left' => (int)$room['moves_left'],
        'start' => $puzzle['start'],
        'end' => $puzzle['end'],
        'my_blockers' => $myBlockers,
        'revealed_blockers' => $revealed,
        'placed' => $placed,
        'ping' => $ping,
        'puzzle_solved' => ((int)$room['puzzle_solved'] === 1),
        'spark' => [
            'question' => $spark['q'],
            'options' => [$spark['a'], $spark['b']],
            'my_answer' => ($role === 'A') ? $sparkA : $sparkB,
            'partner_answered' => ($role === 'A') ? ($sparkB !== null) : ($sparkA !== null),
            'both_answered' => $bothAnswered,
            'answer_a' => $bothAnswered ? $sparkA : null,
            'answer_b' => $bothAnswered ? $sparkB : null,
        ],
    ];
}

function getAuthorizedRoom(PDO $pdo, string $code, string $token): array {
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
    $stmt->execute([$code]);
    $room = $stmt->fetch();
    if (!$room) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Room not found.']);
        exit;
    }

    $role = null;
    if ($room['player_a_token'] === $token) $role = 'A';
    elseif ($room['player_b_token'] === $token) $role = 'B';

    if (!$role) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'This private signal is already connected between two people.']);
        exit;
    }

    $room = syncRoomDayAndStreak($pdo, $room);
    return [$room, $role];
}

// =====================================================================
// 4. API ROUTER (?api=...)
// =====================================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];

    if ($action === 'dashboard') {
        $token = cleanToken($_GET['token'] ?? '');
        if ($token === '') {
            echo json_encode(['ok' => true, 'rooms' => [], 'seconds_left' => secondsUntilMidnight()]);
            exit;
        }
        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE player_a_token = ? OR player_b_token = ? ORDER BY created_at DESC');
        $stmt->execute([$token, $token]);
        $rows = $stmt->fetchAll();

        $list = [];
        $today = currentDayIndex();
        foreach ($rows as $row) {
            $row = syncRoomDayAndStreak($pdo, $row);
            $role = ($row['player_a_token'] === $token) ? 'A' : 'B';
            $list[] = [
                'code' => $row['code'],
                'role' => $role,
                'partner_name' => ($role === 'A') ? ($row['player_b_name'] ?: 'Waiting for invite...') : $row['player_a_name'],
                'partner_joined' => !empty($row['player_b_token']),
                'streak' => (int)$row['streak'],
                'broken_streak' => (int)$row['broken_streak'],
                'completed_today' => ((int)$row['last_completed_day'] === $today),
                'my_turn' => ($row['turn_player'] === $role) && ((int)$row['puzzle_solved'] === 0),
                'puzzle_solved' => ((int)$row['puzzle_solved'] === 1),
            ];
        }
        echo json_encode(['ok' => true, 'rooms' => $list, 'seconds_left' => secondsUntilMidnight()]);
        exit;
    }

    if ($action === 'create_room') {
        $in = jsonInput();
        $token = cleanToken($in['token'] ?? '');
        $name = cleanName($in['name'] ?? 'Player 1');
        if ($token === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing player token.']);
            exit;
        }

        $code = generateRoomCode($pdo);
        $today = currentDayIndex();
        $stmt = $pdo->prepare("
            INSERT INTO rooms (code, player_a_token, player_a_name, active_day, turn_player, moves_left, created_at)
            VALUES (?, ?, ?, ?, 'A', 3, ?)
        ");
        $stmt->execute([$code, $token, $name, $today, time()]);

        [$room, $role] = getAuthorizedRoom($pdo, $code, $token);
        echo json_encode(['ok' => true, 'room' => buildRoomPayload($room, $role)]);
        exit;
    }

    if ($action === 'join_room') {
        $in = jsonInput();
        $token = cleanToken($in['token'] ?? '');
        $name = cleanName($in['name'] ?? 'Player 2');
        $code = cleanCode($in['code'] ?? '');
        if ($token === '' || strlen($code) < 4) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Enter a valid 6-character room code.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM rooms WHERE code = ?');
        $stmt->execute([$code]);
        $room = $stmt->fetch();
        if (!$room) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Room code not found.']);
            exit;
        }

        if ($room['player_a_token'] === $token || $room['player_b_token'] === $token) {
            [$room, $role] = getAuthorizedRoom($pdo, $code, $token);
            echo json_encode(['ok' => true, 'room' => buildRoomPayload($room, $role)]);
            exit;
        }

        if (!empty($room['player_b_token'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'This private room already has two players.']);
            exit;
        }

        $stmt = $pdo->prepare('UPDATE rooms SET player_b_token = ?, player_b_name = ? WHERE code = ?');
        $stmt->execute([$token, $name, $code]);

        [$room, $role] = getAuthorizedRoom($pdo, $code, $token);
        echo json_encode(['ok' => true, 'room' => buildRoomPayload($room, $role)]);
        exit;
    }

    if ($action === 'get_room') {
        $code = cleanCode($_GET['code'] ?? '');
        $token = cleanToken($_GET['token'] ?? '');
        [$room, $role] = getAuthorizedRoom($pdo, $code, $token);
        echo json_encode(['ok' => true, 'room' => buildRoomPayload($room, $role)]);
        exit;
    }

    if ($action === 'place_tile') {
        $in = jsonInput();
        $code = cleanCode($in['code'] ?? '');
        $token = cleanToken($in['token'] ?? '');
        $r = (int)($in['r'] ?? -1);
        $c = (int)($in['c'] ?? -1);

        if ($r < 0 || $r > 4 || $c < 0 || $c > 4) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid cell.']);
            exit;
        }

        [$room, $role] = getAuthorizedRoom($pdo, $code, $token);
        if (empty($room['player_b_token'])) {
            echo json_encode(['ok' => false, 'error' => 'Share the invite link first so your partner joins!']);
            exit;
        }
        if ((int)$room['puzzle_solved'] === 1) {
            echo json_encode(['ok' => true, 'room' => buildRoomPayload($room, $role)]);
            exit;
        }
        if ($room['turn_player'] !== $role) {
            echo json_encode(['ok' => false, 'error' => "Wait for your partner's turn."]);
            exit;
        }
        if ((int)$room['moves_left'] <= 0) {
            echo json_encode(['ok' => false, 'error' => 'No moves left this turn. Tap Pass Turn!']);
            exit;
        }

        $today = currentDayIndex();
        $puzzle = generateDailyPuzzle($code, $today);
        $key = "$r,$c";

        if ($key === $puzzle['start'] || $key === $puzzle['end']) {
            echo json_encode(['ok' => false, 'error' => 'Anchor endpoints are already lit.']);
            exit;
        }

        $myBlockers = ($role === 'A') ? $puzzle['blockersA'] : $puzzle['blockersB'];
        $partnerBlockers = ($role === 'A') ? $puzzle['blockersB'] : $puzzle['blockersA'];
        $revealed = json_decode($room['revealed_json'], true) ?: [];
        $placed = json_decode($room['placed_json'], true) ?: [];
