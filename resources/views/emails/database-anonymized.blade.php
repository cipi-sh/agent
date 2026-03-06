<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Anonymization Complete</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .content { margin-bottom: 20px; }
        .download-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin: 10px 0;
        }
        .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .footer { font-size: 12px; color: #666; border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Database Anonymization Complete</h1>
            <p>Your anonymized database dump is ready for download.</p>
        </div>

        <div class="content">
            <p>Job ID: <strong>{{ $jobId }}</strong></p>

            <p>Your database has been successfully anonymized according to your configuration. The anonymized SQL dump is ready for download.</p>

            <a href="{{ $downloadUrl }}" class="download-button">Download Anonymized Database</a>

            <div class="warning">
                <strong>Important:</strong>
                <ul>
                    <li>The download link expires in {{ $expiresIn }}</li>
                    <li>The file contains sensitive data - keep it secure</li>
                    <li>This is a one-time download link</li>
                </ul>
            </div>

            <p><strong>What's been anonymized:</strong></p>
            <ul>
                <li>Personal information (names, emails, addresses)</li>
                <li>Passwords (re-hashed with secure algorithms)</li>
                <li>Any other sensitive data according to your configuration</li>
            </ul>
        </div>

        <div class="footer">
            <p>This email was sent by Cipi Agent on {{ config('app.name') }}.</p>
            <p>If you didn't request this anonymization, please ignore this email.</p>
        </div>
    </div>
</body>
</html>