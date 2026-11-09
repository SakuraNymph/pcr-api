<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 30px; background-color: #f3f4f6; font-family: Arial, 'Microsoft YaHei', sans-serif;">

    <div style="max-width: 560px; margin: 0 auto; padding: 30px; background-color: #ffffff; border-radius: 12px;">

        <h2 style="margin-top: 0; color: #2563eb; text-align: center;">
            {{ $title }}
        </h2>

        <p style="color: #374151; font-size: 15px; line-height: 1.8;">
            你好，{{ $nickname }}！
        </p>

        <p style="color: #374151; font-size: 15px; line-height: 1.8;">
            这是一封通过 Laravel Blade 模板发送的 HTML 测试邮件。
        </p>

        <div style="margin: 25px 0; padding: 20px; background-color: #eff6ff; border-radius: 8px; text-align: center;">

            <p style="margin: 0 0 10px; color: #6b7280; font-size: 14px;">
                测试验证码
            </p>

            <div style="color: #2563eb; font-size: 32px; font-weight: bold; letter-spacing: 8px;">
                {{ $code }}
            </div>

        </div>

        <p style="color: #6b7280; font-size: 13px; line-height: 1.8;">
            如果你能看到完整排版，说明 Laravel Blade 邮件模板渲染正常。
        </p>

        <hr style="margin: 25px 0; border: none; border-top: 1px solid #e5e7eb;">

        <p style="margin-bottom: 0; color: #9ca3af; font-size: 12px; text-align: center;">
            此邮件仅用于测试，请勿回复。
        </p>

    </div>

</body>
</html>