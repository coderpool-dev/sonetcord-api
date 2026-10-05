<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>SonetCord API</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #0e0f14;
            --surface: #16181f;
            --border: rgba(255,255,255,0.07);
            --accent: #5865F2;
            --accent-dim: rgba(88,101,242,0.15);
            --cyan: #00F2EA;
            --text: #e8eaf0;
            --muted: rgba(232,234,240,0.45);
            --subtle: rgba(232,234,240,0.18);
        }

        html, body { height: 100%; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            flex-direction: column;
        }

        /* grid overlay */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 48px 48px;
            pointer-events: none;
            z-index: 0;
        }

        /* glow blob */
        body::after {
            content: '';
            position: fixed;
            top: -180px;
            left: 50%;
            transform: translateX(-50%);
            width: 700px;
            height: 420px;
            background: radial-gradient(ellipse, rgba(88,101,242,0.18) 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        header {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 52px;
            border-bottom: 1px solid var(--border);
        }

        .logo {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text);
        }
        .logo span { color: var(--cyan); }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 13px;
            font-weight: 500;
            color: #4ade80;
        }

        .status::before {
            content: '';
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #4ade80;
            box-shadow: 0 0 8px #4ade80;
            animation: pulse 2.5s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.45; }
        }

        .version {
            font-size: 12px;
            font-weight: 500;
            color: var(--subtle);
            letter-spacing: 0.3px;
        }

        main {
            flex: 1;
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px 24px;
        }

        .content {
            max-width: 700px;
            width: 100%;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 24px;
        }

        .eyebrow::before {
            content: '';
            display: block;
            width: 24px;
            height: 1px;
            background: var(--accent);
        }

        h1 {
            font-size: 54px;
            font-weight: 700;
            line-height: 1.08;
            letter-spacing: -1.5px;
            margin-bottom: 24px;
        }

        h1 em {
            font-style: normal;
            color: var(--cyan);
        }

        .description {
            font-size: 17px;
            line-height: 1.7;
            color: var(--muted);
            max-width: 560px;
            margin-bottom: 44px;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 64px;
        }

        .btn-main {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 28px;
            background: var(--accent);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            border-radius: 8px;
            transition: background 0.2s, transform 0.2s;
        }

        .btn-main:hover {
            background: #6872f5;
            transform: translateY(-1px);
        }

        .btn-main svg { flex-shrink: 0; }

        .link-secondary {
            font-size: 14px;
            color: var(--subtle);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .link-secondary:hover { color: var(--text); }

        .desktop-card {
            display: grid;
            grid-template-columns: 72px 1fr auto;
            gap: 18px;
            align-items: center;
            padding: 18px;
            margin-bottom: 44px;
            border: 1px solid rgba(88,101,242,0.24);
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(88,101,242,0.16), rgba(255,255,255,0.035));
            box-shadow: 0 22px 70px rgba(0,0,0,0.28);
        }

        .desktop-icon {
            width: 72px;
            height: 72px;
            border-radius: 18px;
            object-fit: cover;
            box-shadow: 0 12px 34px rgba(88,101,242,0.22);
        }

        .desktop-card h2 {
            font-size: 18px;
            line-height: 1.2;
            margin-bottom: 6px;
        }

        .desktop-card p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
            gap: 8px;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 8px;
            background: #fff;
            color: #111214;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.2s, opacity 0.2s;
        }

        .btn-download:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }

        /* endpoints table */
        .table-wrap {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
        }

        .table-head {
            display: grid;
            grid-template-columns: 68px 1fr 1fr;
            padding: 10px 20px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: var(--subtle);
        }

        .row {
            display: grid;
            grid-template-columns: 68px 1fr 1fr;
            padding: 13px 20px;
            border-bottom: 1px solid var(--border);
            align-items: center;
            transition: background 0.15s;
        }

        .row:last-child { border-bottom: none; }
        .row:hover { background: rgba(255,255,255,0.025); }

        .method {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
            padding: 2px 7px;
            border-radius: 4px;
            width: fit-content;
        }

        .m-post { background: var(--accent-dim); color: #99a7ff; }
        .m-get  { background: rgba(0,242,234,0.1); color: var(--cyan); }
        .m-del  { background: rgba(248,113,113,0.12); color: #f87171; }

        .path {
            font-family: 'Consolas', 'SF Mono', monospace;
            font-size: 13px;
            color: var(--text);
        }

        .desc {
            font-size: 13px;
            color: var(--muted);
        }

        footer {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 52px;
            border-top: 1px solid var(--border);
            font-size: 13px;
            color: var(--subtle);
        }

        footer a {
            color: var(--subtle);
            text-decoration: none;
            transition: color 0.2s;
        }
        footer a:hover { color: var(--text); }

        @media (max-width: 640px) {
            header, footer { padding: 18px 20px; }
            h1 { font-size: 36px; letter-spacing: -1px; }
            main { padding: 52px 20px; }
            .desktop-card { grid-template-columns: 56px 1fr; }
            .desktop-icon { width: 56px; height: 56px; border-radius: 14px; }
            .btn-download { grid-column: 1 / -1; }
            .table-head, .row { grid-template-columns: 60px 1fr; }
            .desc { display: none; }
        }
    </style>
</head>
<body>

<header>
    <div class="logo">Sonet<span>Cord</span></div>
    <div class="header-right">
        <span class="status">Работает</span>
        <span class="version">API / v1</span>
    </div>
</header>

<main>
    <div class="content">

        <span class="eyebrow">REST API</span>

        <h1>Серверная часть<br><em>SonetCord</em></h1>

        <p class="description">
            Этот сервер обрабатывает все запросы от клиентского приложения — авторизацию,
            обмен сообщениями в реальном времени, звонки и управление каналами.
            Если вы искали сам мессенджер — он находится на основном сайте.
        </p>

        <div class="actions">
            <a href="https://sonetcord.ru" class="btn-main">
                <svg width="15" height="15" viewBox="0 0 15 15" fill="none">
                    <path d="M3 7.5h9M8.5 4l3.5 3.5L8.5 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Открыть SonetCord
            </a>
            <a href="https://sonetcord.ru" class="link-secondary">sonetcord.ru →</a>
        </div>

        <div class="desktop-card">
            <img class="desktop-icon" src="/images/goydacord-app-icon.png" alt="">
            <div>
                <h2>SonetCord for Windows</h2>
                <p>Desktop window, tray mode, autostart and quick access to calls without keeping a browser tab open.</p>
            </div>
            <a class="btn-download" href="https://sonetcord.ru/downloads/windows">Download .exe</a>
        </div>

        <div class="table-wrap">
            <div class="table-head">
                <span>Метод</span>
                <span>Маршрут</span>
                <span>Описание</span>
            </div>
            <div class="row">
                <span class="method m-post">POST</span>
                <span class="path">/api/register</span>
                <span class="desc">Создание аккаунта</span>
            </div>
            <div class="row">
                <span class="method m-post">POST</span>
                <span class="path">/api/login</span>
                <span class="desc">Получение токена</span>
            </div>
            <div class="row">
                <span class="method m-get">GET</span>
                <span class="path">/api/channels</span>
                <span class="desc">Список каналов пользователя</span>
            </div>
            <div class="row">
                <span class="method m-get">GET</span>
                <span class="path">/api/messages/{channelId}</span>
                <span class="desc">История сообщений канала</span>
            </div>
            <div class="row">
                <span class="method m-post">POST</span>
                <span class="path">/api/messages</span>
                <span class="desc">Отправка сообщения</span>
            </div>
            <div class="row">
                <span class="method m-get">GET</span>
                <span class="path">/api/friends</span>
                <span class="desc">Список друзей</span>
            </div>
            <div class="row">
                <span class="method m-post">POST</span>
                <span class="path">/api/calls/{channelId}</span>
                <span class="desc">Инициировать звонок</span>
            </div>
            <div class="row">
                <span class="method m-del">DEL</span>
                <span class="path">/api/channels/{id}</span>
                <span class="desc">Удалить канал</span>
            </div>
        </div>

    </div>
</main>

<footer>
    <span>© 2025 SonetCord</span>
    <a href="https://sonetcord.ru">sonetcord.ru</a>
</footer>

</body>
</html>
