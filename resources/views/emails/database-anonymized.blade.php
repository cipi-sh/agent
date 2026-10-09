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
        .download-button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
        .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .stats { background-color: #f8f9fa; padding: 12px 15px; border-radius: 5px; font-size: 14px; }
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

            <p>The database of <strong>{{ config('app.name') }}</strong> has been dumped and anonymized according to the server-side configuration.</p>

            <a href="{{ $downloadUrl }}" class="download-button">Download Anonymized Database</a>

            @if (! empty($report))
                <div class="stats">
                    <strong>Summary</strong><br>
                    Size: {{ $report['size'] ?? '?' }}{{ ! empty($report['gzip']) ? ' (gzip)' : '' }}<br>
                    Rows transformed: {{ $report['rows_transformed'] ?? 0 }} in {{ count($report['tables'] ?? []) }} table(s)<br>
                    @if (! empty($report['truncated']))
                        Tables emptied: {{ implode(', ', array_keys($report['truncated'])) }}<br>
                    @endif
                    Duration: {{ $report['duration_seconds'] ?? '?' }}s
                    @if (! empty($report['warnings']))
                        <br><br><strong>Warnings</strong><br>
                        @foreach ($report['warnings'] as $warning)
                            &bull; {{ $warning }}<br>
                        @endforeach
                    @endif
                </div>
            @endif

            <div class="warning">
                <strong>Important:</strong>
                <ul>
                    <li>The download link expires in {{ $expiresIn }}</li>
                    <li>The dump is anonymized, but still treat it as confidential</li>
                    <li>Passwords are reset to the value configured on the server (default: <code>password</code>)</li>
                </ul>
            </div>
        </div>

        <div class="footer">
            <p>This email was sent by Cipi Agent on {{ config('app.name') }}.</p>
            <p>If you didn't request this anonymization, please contact your administrator.</p>
        </div>
    </div>
</body>
</html>
