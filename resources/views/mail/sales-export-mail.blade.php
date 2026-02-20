<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export ventes</title>
    <style>
        body        { font-family: Arial, sans-serif; background: #f4f6f8; margin: 0; padding: 20px; color: #2d3748; }
        .container  { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .header     { background: #2d3748; color: #fff; padding: 28px 32px; }
        .header h1  { margin: 0; font-size: 20px; font-weight: 700; }
        .header p   { margin: 6px 0 0; font-size: 13px; opacity: .75; }
        .body       { padding: 28px 32px; }
        .body p     { font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
        .pill-grid  { display: flex; flex-wrap: wrap; gap: 10px; margin: 20px 0; }
        .pill       { background: #edf2f7; border-radius: 20px; padding: 6px 14px; font-size: 13px; font-weight: 600; color: #4a5568; }
        .info-box   { background: #f7fafc; border-left: 4px solid #4299e1; border-radius: 4px; padding: 14px 18px; margin: 20px 0; font-size: 13px; }
        .info-box strong { color: #2b6cb0; }
        .footer     { background: #f7fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px; font-size: 12px; color: #a0aec0; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Export ventes par pays</h1>
            <p>Généré automatiquement le {{ now()->format('d/m/Y à H:i') }}</p>
        </div>

        <div class="body">
            <p>Bonjour,</p>
            <p>
                Veuillez trouver en pièce(s) jointe(s) le(s) <strong>{{ $fileCount }} fichier(s) Excel</strong>
                contenant le top 100 des ventes par pays pour la période
                <strong>{{ \Carbon\Carbon::parse($dateFrom)->format('d/m/Y') }}</strong>
                au
                <strong>\Carbon\Carbon::parse($dateTo)->format('d/m/Y') }}</strong>.
            </p>

            <div class="info-box">
                <strong>Pays exportés :</strong><br><br>
                <div class="pill-grid">
                    @foreach($countriesGenerated as $code => $label)
                        <span class="pill">{{ $label }} ({{ $code }})</span>
                    @endforeach
                </div>
            </div>

            <p>
                Chaque fichier contient les colonnes suivantes : rang quantité, rang CA,
                EAN, groupe, marque, désignation, prix Cosma, quantité vendue, CA total,
                PGHT, prix par site concurrent ainsi que le prix moyen du marché.
            </p>

            <p style="font-size:13px; color:#718096;">
                Ce rapport est généré automatiquement. Ne pas répondre à cet email.
            </p>
        </div>

        <div class="footer">
            © {{ date('Y') }} Cosma — Export automatique des ventes
        </div>
    </div>
</body>
</html>