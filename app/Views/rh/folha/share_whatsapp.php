<?php

use App\Helpers\View;

/** @var string $token */
/** @var string $portalUrl */
/** @var string $ogUrl */
/** @var string $ogImage */
/** @var string $ogTitle */
/** @var string $ogDesc */
/** @var string $periodoTexto */
/** @var string $nomeColaborador */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= View::e($ogTitle) ?></title>
    <meta name="description" content="<?= View::e($ogDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Arcadis RH — Corredor Norte">
    <meta property="og:title" content="<?= View::e($ogTitle) ?>">
    <meta property="og:description" content="<?= View::e($ogDesc) ?>">
    <meta property="og:url" content="<?= View::e($ogUrl) ?>">
    <meta property="og:image" content="<?= View::e($ogImage) ?>">
    <meta property="og:image:secure_url" content="<?= View::e($ogImage) ?>">
    <meta property="og:image:type" content="image/jpeg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Arcadis RH — Corredor Norte">
    <meta property="og:locale" content="pt_BR">
    <link rel="image_src" href="<?= View::e($ogImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= View::e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= View::e($ogDesc) ?>">
    <meta name="twitter:image" content="<?= View::e($ogImage) ?>">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f172a;
            color: #111;
            font-family: "Source Sans 3", system-ui, -apple-system, sans-serif;
        }
        .card {
            width: min(420px, 92vw);
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(0,0,0,.35);
            text-align: center;
        }
        .brand {
            background: #f3f4f6;
            padding: 28px 24px 20px;
            border-bottom: 4px solid #ea580c;
        }
        .brand img {
            max-width: 260px;
            width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
        }
        .body { padding: 24px 22px 28px; }
        h1 { margin: 0 0 8px; font-size: 1.25rem; color: #0f172a; }
        p { margin: 0 0 18px; color: #475569; line-height: 1.45; }
        .btn {
            display: inline-block;
            background: #ea580c;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            padding: 12px 22px;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <img src="<?= View::e($ogImage) ?>" width="1200" height="630" alt="Arcadis RH">
        </div>
        <div class="body">
            <h1>Folha de Ponto</h1>
            <p>
                <?php if ($nomeColaborador !== ''): ?>
                    <?= View::e($nomeColaborador) ?><?= $periodoTexto !== '' ? ' · ' . View::e($periodoTexto) : '' ?>
                <?php else: ?>
                    Conferência e assinatura eletrônica
                <?php endif; ?>
            </p>
            <a class="btn" href="<?= View::e($portalUrl) ?>">Abrir e assinar</a>
        </div>
    </div>
    <script>
      // Humanos seguem para o portal; crawlers do WhatsApp leem o HTML/OG acima.
      setTimeout(function () {
        window.location.replace(<?= json_encode($portalUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>);
      }, 80);
    </script>
</body>
</html>
