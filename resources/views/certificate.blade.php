<!DOCTYPE html>
<html>
<head>
    <style>
        @font-face {
            font-family: 'cairo'; /* Must match 'default_font' in PHP */
            src: url('{{ storage_path("fonts/Cairo-Regular.ttf") }}') format('truetype');
        }
        body {
            margin: 0;
            padding: 0;
            background: url('{{ public_path("certificate.png") }}') no-repeat center center;
            background-size: 100% 100%;
            width: 237mm;
            height: 162mm;
            /* Try Cairo first, then system fonts for Arabic */
            font-family: 'cairo', sans-serif; /* Use the same name */
            position: relative;
            direction: rtl;
            /* Force font rendering */
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .content {
            position: absolute;
            top: 65mm;
            left: 0;
            right: 0;
            width: 420mm;
            margin: 0 auto;
            text-align: center;
            color: #000;
        }

        .name {
            font-size: 25px;
            font-weight: bold;
            color: #1a237e;
            font-family: 'Cairo', 'Arial', sans-serif;
        }

        .course {
            font-weight: bold;
            font-size: 20px;
            margin: 65px 0;
            color: #9f895c;
            font-family: 'Cairo', 'Arial', sans-serif;
        }

        .time {
            font-weight: bold;
            font-size: 18px;
            margin: 75px 0;
            color: #9f895c;
            font-family: 'Cairo', 'Arial', sans-serif;
        }

        .date {
            font-weight: bold;
            font-size: 18px;
            margin: 85px 0;
            color: #9f895c;
            font-family: 'Cairo', 'Arial', sans-serif;
        }
    </style>
</head>
<body>
    <div class="content">
        <div class="name">السيد / {{ $name }}</div>
        <div class="course">( {{ $course }} )</div>
        <div class="time">لمدة {{ $time }} خلال الفترة </div>
        <div class="date">من {{ $date_from }} هـ إلى الفترة {{ $date_to }} هـ</div>
    </div>
</body>
</html>
