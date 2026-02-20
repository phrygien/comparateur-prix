<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export ventes</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            font-weight: 400;
            background: #f0f4f8;
            margin: 0;
            padding: 32px 16px;
            color: #1a202c;
        }

        .container {
            max-width: 580px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 4px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        /* ── Header ── */
        .header {
            background: #1a365d;
            padding: 36px 40px 30px;
        }

        .header h1 {
            font-family: 'DM Serif Display', serif;
            font-weight: 400;
            font-size: 22px;
            letter-spacing: 0.01em;
            color: #ffffff;
            margin: 0 0 8px;
        }

        .header p {
            font-size: 12px;
            font-weight: 300;
            color: #90cdf4;
            margin: 0;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        /* ── Body ── */
        .body {
            padding: 36px 40px;
        }

        .body p {
            font-size: 14px;
            font-weight: 400;
            line-height: 1.75;
            color: #2d3748;
            margin: 0 0 18px;
        }

        .body strong {
            font-weight: 600;
            color: #1a202c;
        }

        /* ── Divider ── */
        .divider {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin: 24px 0;
        }

        /* ── Info box ── */
        .info-box {
            background: #ebf8ff;
            border-left: 3px solid #3182ce;
            border-radius: 2px;
            padding: 16px 20px;
            margin: 4px 0 24px;
            font-size: 13px;
            color: #2c5282;
            font-weight: 500;
        }

        /* ── Pills ── */
        .pill-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .pill {
            background: #fff;
            border: 1px solid #bee3f8;
            border-radius: 2px;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 0.03em;
            color: #2b6cb0;
        }

        /* ── Note ── */
        .note {
            font-size: 12px !important;
            font-weight: 300 !important;
            color: #a0aec0 !important;
            letter-spacing: 0.02em;
        }

        /* ── Footer ── */
        .footer {
            border-top: 1px solid #e2e8f0;
            padding: 16px 40px;
            font-size: 11px;
            font-weight: 300;
            color: #a0aec0;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">

        <div class="header">
            <h1>Export ventes par pays</h1>
            <p>Généré le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>

        <div class="body">
            <p>Bonjour,</p>
            <p>
                Veuillez trouver en pièce(s) jointe(s) <strong>{{ $fileCount }} fichier(s) Excel</strong>
                contenant le top 100 des ventes par pays pour la période du
                <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}</strong>
                au
                <strong>{{ \Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}</strong>.
            </p>

            <hr class="divider">

            <div class="info-box">
                Pays exportés
                <div class="pill-grid">
                    @foreach($countriesGenerated as $code => $label)
                        <span class="pill">{{ $label }} · {{ $code }}</span>
                    @endforeach
                </div>
            </div>

            <p>
                Chaque fichier contient les colonnes suivantes : rang quantité, rang CA,
                EAN, groupe, marque, désignation, prix Cosma, quantité vendue, CA total,
                PGHT, prix par site concurrent ainsi que le prix moyen du marché.
            </p>

            <p class="note">Ce rapport est généré automatiquement — merci de ne pas répondre à cet email.</p>
        </div>

        <div class="footer">
            © {{ date('Y') }} Cosma &nbsp;·&nbsp; Export automatique des ventes
        </div>

    </div>
</body>
</html>