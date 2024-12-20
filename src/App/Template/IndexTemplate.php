<?php

use OpenCCK\App\Controller\MainController;
/** @var MainController $this */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <title>IP Address Collection Service</title>

    <link rel="apple-touch-icon" sizes="57x57" href="/images/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="/images/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/images/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="/images/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/images/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/images/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/images/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/images/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/images/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="512x512"  href="/images/icon-512.png">
    <link rel="icon" type="image/png" sizes="192x192"  href="/images/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/images/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/images/favicon-16x16.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="/images/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">

    <style>
        /* MVP.css v1.15 - https://github.com/andybrewer/mvp */
        :root {
            --active-brightness: 0.85;
            --border-radius: 5px;
            --box-shadow: 2px 2px 10px;
            --color-accent: #118bee15;
            --color-bg: #fff;
            --color-bg-secondary: #e9e9e9;
            --color-link: #118bee;
            --color-secondary: #920de9;
            --color-secondary-accent: #920de90b;
            --color-shadow: #f4f4f4;
            --color-table: #118bee;
            --color-text: #000;
            --color-text-secondary: #999;
            --color-scrollbar: #cacae8;
            --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            --hover-brightness: 1.2;
            --justify-important: center;
            --justify-normal: left;
            --line-height: 1.5;
            --width-card: 285px;
            --width-card-medium: 460px;
            --width-card-wide: 800px;
            --width-content: 1080px;
        }

        @media (prefers-color-scheme: dark) {
            :root[color-mode="user"] {
                --color-accent: #0097fc4f;
                --color-bg: #333;
                --color-bg-secondary: #555;
                --color-link: #0097fc;
                --color-secondary: #e20de9;
                --color-secondary-accent: #e20de94f;
                --color-shadow: #bbbbbb20;
                --color-table: #0097fc;
                --color-text: #f7f7f7;
                --color-text-secondary: #aaa;
            }
        }

        html {
            scroll-behavior: smooth;
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }
        }

        /* Layout */
        article aside {
            background: var(--color-secondary-accent);
            border-left: 4px solid var(--color-secondary);
            padding: 0.01rem 0.8rem;
        }

        body {
            background: var(--color-bg);
            color: var(--color-text);
            font-family: var(--font-family);
            line-height: var(--line-height);
            margin: 0;
            overflow-x: hidden;
            padding: 0;
        }

        footer,
        header,
        main {
            margin: 0 auto;
            max-width: var(--width-content);
            padding: 3rem 1rem;
        }

        hr {
            background-color: var(--color-bg-secondary);
            border: none;
            height: 1px;
            margin: 4rem 0;
            width: 100%;
        }

        section {
            display: flex;
            flex-wrap: wrap;
            justify-content: var(--justify-important);
        }

        section img,
        article img {
            max-width: 100%;
        }

        section pre {
            overflow: auto;
        }

        section aside {
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow) var(--color-shadow);
            margin: 1rem;
            padding: 1.25rem;
            width: var(--width-card);
        }

        section aside:hover {
            box-shadow: var(--box-shadow) var(--color-bg-secondary);
        }

        [hidden] {
            display: none;
        }

        /* Headers */
        article header,
        div header,
        main header {
            padding-top: 0;
        }

        header {
            text-align: var(--justify-important);
        }

        header a b,
        header a em,
        header a i,
        header a strong {
            margin-left: 0.5rem;
            margin-right: 0.5rem;
        }

        header nav img {
            margin: 1rem 0;
        }

        section header {
            padding-top: 0;
            width: 100%;
        }

        /* Nav */
        nav {
            align-items: center;
            display: flex;
            font-weight: bold;
            justify-content: space-between;
            margin-bottom: 7rem;
        }

        nav ul {
            list-style: none;
            padding: 0;
        }

        nav ul li {
            display: inline-block;
            margin: 0 0.5rem;
            position: relative;
            text-align: left;
        }

        /* Nav Dropdown */
        nav ul li:hover ul {
            display: block;
        }

        nav ul li ul {
            background: var(--color-bg);
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow) var(--color-shadow);
            display: none;
            height: auto;
            left: -2px;
            padding: .5rem 1rem;
            position: absolute;
            top: 1.7rem;
            white-space: nowrap;
            width: auto;
            z-index: 1;
        }

        nav ul li ul::before {
            /* fill gap above to make mousing over them easier */
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: -0.5rem;
            height: 0.5rem;
        }

        nav ul li ul li,
        nav ul li ul li a {
            display: block;
        }

        /* Typography */
        code,
        samp {
            background-color: var(--color-accent);
            border-radius: var(--border-radius);
            color: var(--color-text);
            display: inline-block;
            margin: 0 0.1rem;
            padding: 0 0.5rem;
        }

        details {
            margin: 1.3rem 0;
        }

        details summary {
            font-weight: bold;
            cursor: pointer;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            line-height: var(--line-height);
        }

        mark {
            padding: 0.1rem;
        }

        ol li,
        ul li {
            padding: 0.2rem 0;
        }

        p {
            margin: 0.75rem 0;
            padding: 0;
            width: 100%;
        }

        pre {
            margin: 1rem 0;
            max-width: var(--width-card-wide);
            padding: 1rem 0;
        }

        pre code,
        pre samp {
            display: block;
            max-width: var(--width-card-wide);
            padding: 0.5rem 2rem;
            white-space: pre-wrap;
        }

        small {
            color: var(--color-text-secondary);
        }

        sup {
            background-color: var(--color-secondary);
            border-radius: var(--border-radius);
            color: var(--color-bg);
            font-size: xx-small;
            font-weight: bold;
            margin: 0.2rem;
            padding: 0.2rem 0.3rem;
            position: relative;
            top: -2px;
        }

        /* Links */
        a {
            color: var(--color-link);
            display: inline-block;
            font-weight: bold;
            text-decoration: underline;
        }

        a:hover {
            filter: brightness(var(--hover-brightness));
        }

        a:active {
            filter: brightness(var(--active-brightness));
        }

        a b,
        a em,
        a i,
        a strong,
        button,
        input[type="submit"] {
            border-radius: var(--border-radius);
            display: inline-block;
            font-size: medium;
            font-weight: bold;
            line-height: var(--line-height);
            margin: 0.5rem 0;
            padding: 1rem 2rem;
        }

        button,
        input[type="submit"] {
            font-family: var(--font-family);
        }

        button:hover,
        input[type="submit"]:hover {
            cursor: pointer;
            filter: brightness(var(--hover-brightness));
        }

        button:active,
        input[type="submit"]:active {
            filter: brightness(var(--active-brightness));
        }

        a b,
        a strong,
        button,
        input[type="submit"] {
            background-color: var(--color-link);
            border: 2px solid var(--color-link);
            color: var(--color-bg);
        }

        a em,
        a i {
            border: 2px solid var(--color-link);
            border-radius: var(--border-radius);
            color: var(--color-link);
            display: inline-block;
            padding: 1rem 2rem;
        }

        article aside a {
            color: var(--color-secondary);
        }

        /* Images */
        figure {
            margin: 0;
            padding: 0;
        }

        figure img {
            max-width: 100%;
        }

        figure figcaption {
            color: var(--color-text-secondary);
        }

        /* Forms */
        button:disabled,
        input:disabled {
            background: var(--color-bg-secondary);
            border-color: var(--color-bg-secondary);
            color: var(--color-text-secondary);
            cursor: not-allowed;
        }

        button[disabled]:hover,
        input[type="submit"][disabled]:hover {
            filter: none;
        }

        form {
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow) var(--color-shadow);
            display: block;
            max-width: var(--width-card-wide);
            min-width: var(--width-card);
            padding: 1.5rem;
            text-align: var(--justify-normal);
        }

        form header {
            margin: 1.5rem 0;
            padding: 1.5rem 0;
        }

        input,
        label,
        select,
        textarea {
            display: block;
            font-size: inherit;
            max-width: var(--width-card-wide);
        }

        input[type="checkbox"],
        input[type="radio"] {
            display: inline-block;
        }

        input[type="checkbox"]+label,
        input[type="radio"]+label {
            display: inline-block;
            font-weight: normal;
            position: relative;
            top: 1px;
        }

        input[type="range"] {
            padding: 0.4rem 0;
        }

        input,
        select,
        textarea {
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            padding: 0.4rem 0.8rem;
        }

        input[type="text"],
        input[type="password"],
        textarea {
            width: calc(100% - 1.6rem);
        }

        input[readonly],
        textarea[readonly] {
            background-color: var(--color-bg-secondary);
        }

        label {
            font-weight: bold;
            margin-bottom: 0.2rem;
        }

        /* Popups */
        dialog {
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow) var(--color-shadow);
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50%;
            z-index: 999;
        }

        /* Tables */
        table {
            border: 1px solid var(--color-bg-secondary);
            border-radius: var(--border-radius);
            border-spacing: 0;
            display: inline-block;
            max-width: 100%;
            overflow-x: auto;
            padding: 0;
            white-space: nowrap;
        }

        table td,
        table th,
        table tr {
            padding: 0.4rem 0.8rem;
            text-align: var(--justify-important);
        }

        table thead {
            background-color: var(--color-table);
            border-collapse: collapse;
            border-radius: var(--border-radius);
            color: var(--color-bg);
            margin: 0;
            padding: 0;
        }

        table thead tr:first-child th:first-child {
            border-top-left-radius: var(--border-radius);
        }

        table thead tr:first-child th:last-child {
            border-top-right-radius: var(--border-radius);
        }

        table thead th:first-child,
        table tr td:first-child {
            text-align: var(--justify-normal);
        }

        table tr:nth-child(even) {
            background-color: var(--color-accent);
        }

        /* Quotes */
        blockquote {
            display: block;
            font-size: x-large;
            line-height: var(--line-height);
            margin: 1rem auto;
            max-width: var(--width-card-medium);
            padding: 1.5rem 1rem;
            text-align: var(--justify-important);
        }

        blockquote footer {
            color: var(--color-text-secondary);
            display: block;
            font-size: small;
            line-height: var(--line-height);
            padding: 1.5rem 0;
        }

        /* Scrollbars */
        * {
            scrollbar-width: thin;
            scrollbar-color: var(--color-scrollbar) transparent;
        }

        *::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
        }

        *::-webkit-scrollbar-thumb {
            background-color: var(--color-scrollbar);
            border-radius: 10px;
        }
    </style>
    <style>
        .main {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            font-size: 18px;
            line-height: 1.2;
        }
        .main-section {
            justify-content: center;
            row-gap: 4px;
            column-gap: 16px;
        }
        .main-form {
        }
        .main-formItem {
            margin-bottom: 0;
        }
        .main-formItem_wide {
            flex: 0 0 100%;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            margin-bottom: 10px;
        }
        .main-formItemComment {
            flex: 0 0 100%;
            display: block;
            font-size: 12px;
            line-height: 1;
            width: 150px;
            text-align: center;
        }
        .main-formSelect {}
        .main-formSelect_site {
            min-height: 60vh;
        }
        .main-formItemCheckbox {
            margin: 0 6px 0 0;
        }
        .main-formItemCheckboxLabel {
            line-height: 1;
            font-size: 14px;
            font-weight: normal;
            cursor: pointer;
        }
        .github {
            flex: 0 0 100%;
            display: flex;
            justify-content: center;
            padding-bottom: 16px;
        }
    </style>
</head>
<body>
    <main class="main">
        <div class="github">
            <iframe src="https://ghbtns.com/github-btn.html?user=rekryt&amp;repo=iplist&amp;type=star&amp;count=true&amp;size=large" frameborder="0" scrolling="0" width="170" height="30" title="GitHub"></iframe>
        </div>
        <form action="" method="get" class="main-form">
            <section class="main-section">
                <label class="main-formItem">
                    Format:
                    <select name="format" class="main-formSelect">
                        <option value="json">JSON</option>
                        <option value="text">Text</option>
                        <option value="comma">Comma</option>
                        <option value="mikrotik">MikroTik Script</option>
                        <option value="switchy">SwitchyOmega RuleList</option>
                        <option value="nfset">Dnsmasq nfset</option>
                        <option value="ipset">Dnsmasq ipset</option>
                        <option value="clashx">ClashX</option>
                        <option value="kvas">Keenetic KVAS</option>
                        <option value="bat">Keenetic Routes (.bat)</option>
                        <option value="amnezia">Amnezia</option>
                        <option value="pac">Proxy auto configuration (PAC)</option>
                    </select>
                </label>
                <label class="main-formItem">
                    Data:
                    <select name="data" class="main-formSelect">
                        <option value="">All</option>
                        <option value="domains">domains</option>
                        <option value="ip4">ip4</option>
                        <option value="cidr4">cidr4</option>
                        <option value="ip6">ip6</option>
                        <option value="cidr6">cidr6</option>
                    </select>
                </label>
                <label class="main-formItem main-formItem_wide">
                    <span>
                        Site:
                        <select name="site" class="main-formSelect main-formSelect_site" multiple>
                            <?php foreach ($this->getGroups() as $group => $items): ?>
                                <optgroup label="<?= $group ?>">
                                    <?php foreach ($items as $site): ?>
                                        <option value="<?= $site->name ?>"><?= $site->name ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <span class="main-formItemComment">Don't choose sites if you want to get everything</span>
                </label>
                <label class="main-formItem main-formItem_wide">
                    <input class="main-formItemCheckbox" type="checkbox" name="wildcard" value="1" />
                    <span class="main-formItemCheckboxLabel">Only wildcard domains</span>
                </label>
                <label class="main-formItem main-formItem_wide">
                    <input class="main-formItemCheckbox" type="checkbox" name="filesave" value="1" />
                    <span class="main-formItemCheckboxLabel">Save as file</span>
                </label>
            </section>
            <section>
                <button type="submit">Submit</button>
            </section>
        </form>
    </main>
    <footer>
        <p style="text-align: center">
            <a href="https://github.com/rekryt/iplist">GitHub</a>
            <a href="https://github.com/rekryt/iplist/issues">Issues</a>
        </p>
    </footer>
</body>
</html>