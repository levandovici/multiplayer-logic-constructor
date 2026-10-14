<?php
require_once dirname(__DIR__) . '/src/bootstrap.php';
send_security_headers();

// Already signed in → cabinet.
if (!empty($_SESSION['user_id'])) {
    header('Location: /cabinet.php');
    exit;
}

$email = is_string($_GET['email'] ?? null) ? $_GET['email'] : '';
$sent = isset($_GET['sent']);
$error = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Developer Login — Multiplayer Logic Constructor</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        .bg-page { background: linear-gradient(135deg, #1e1e2d 0%, #2d1e4a 50%, #1e3a5f 100%); }
        .glass { background: rgba(255,255,255,0.08); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.15); }
    </style>
</head>
<body class="bg-page min-h-screen text-white flex items-center justify-center p-4">
    <div class="glass w-full max-w-md rounded-2xl p-8">
        <h1 class="text-2xl font-bold mb-1">Developer Login</h1>
        <p class="text-gray-300 text-sm mb-6">Passwordless — we email you a code. New email? That registers you.</p>

        <?php if ($error !== ''): ?>
            <div class="bg-red-500/20 border border-red-400/40 text-red-200 rounded-lg px-4 py-3 mb-4 text-sm">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
        <?php if ($sent): ?>
            <div class="bg-green-500/20 border border-green-400/40 text-green-200 rounded-lg px-4 py-3 mb-4 text-sm">
                If that email is registered (or new), a code is on its way. Check your inbox.
            </div>
        <?php endif; ?>

        <?php if (!$sent): ?>
        <form method="POST" action="/php/auth.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request">
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="email">Email</label>
                <input id="email" name="email" type="email" required autofocus
                       value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                       class="w-full bg-white/10 border border-white/20 rounded-lg px-4 py-2.5 focus:outline-none focus:border-purple-400">
            </div>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 rounded-lg py-2.5 font-semibold transition">
                Email me a code
            </button>
        </form>
        <?php else: ?>
        <form method="POST" action="/php/auth.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="code">Login code</label>
                <input id="code" name="code" type="text" required autofocus autocomplete="one-time-code"
                       placeholder="XXXX-XXXX" maxlength="9"
                       class="w-full bg-white/10 border border-white/20 rounded-lg px-4 py-2.5 tracking-widest text-center font-mono text-lg focus:outline-none focus:border-purple-400">
            </div>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-500 rounded-lg py-2.5 font-semibold transition">
                Sign in
            </button>
            <p class="text-center text-sm">
                <a href="/login.php" class="text-gray-400 hover:text-white">Use a different email</a>
            </p>
        </form>
        <?php endif; ?>

        <p class="text-center mt-6 text-sm"><a href="/" class="text-gray-400 hover:text-white">&larr; Home</a></p>
    </div>
</body>
</html>
