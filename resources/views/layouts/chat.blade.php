<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tra cứu tài liệu Phật giáo</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=roboto-condensed:300,400,500,600,700" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['"Roboto Condensed"', 'sans-serif'] }
                }
            }
        }
    </script>
    @livewireStyles
</head>
<body class="bg-amber-50 font-sans">
    {{ $slot }}
    @livewireScripts
</body>
</html>
