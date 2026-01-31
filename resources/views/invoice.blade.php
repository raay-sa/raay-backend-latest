<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="rtl">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $setting->site_name ?? __('trans.application') }}</title>
    <style>
        @font-face {
            font-family: 'cairo';
            src: url('{{ storage_path("fonts/Cairo-Regular.ttf") }}') format('truetype');
        }
        body {
            font-family: 'cairo', sans-serif;
            direction: rtl;
            background-color: #f8fafc;
            color: #222;
            margin: 0;
        }
        .invoice-card {
            border-radius: 12px;
            max-width: 500px;
            margin: 40px auto;
            padding: 32px 28px 24px 28px;
        }
        .invoice-header {
            text-align: center;
            margin-bottom: 24px;
        }
        .invoice-header img {
            width: 120px;
            margin-bottom: 12px;
        }
        .invoice-header p {
            margin: 0;
            color: #666;
            font-size: 15px;
        }
        .divider {
            border: none;
            border-top: 1.5px dashed #bdbdbd;
            margin: 24px 0;
        }
        .invoice-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }
        .invoice-table th, .invoice-table td {
            padding: 10px 8px;
            background: #f4f6fb;
            border-radius: 6px;
            font-size: 15px;
        }
        .invoice-table th {
            background: #e3e7f0;
            color: #333;
            font-weight: 700;
            text-align: right;
        }
        .invoice-table tr:not(:last-child) td {
            border-bottom: 1px solid #e0e0e0;
        }
        .invoice-summary {
            margin-top: 18px;
        }
        .invoice-summary td {
            background: none;
            border: none;
            font-size: 16px;
            color: #222;
            padding: 6px 0;
        }
        .thankyou {
            text-align: center;
            margin-top: 32px;
            color: #4a90e2;
            font-size: 17px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        @media (max-width: 600px) {
            .invoice-card { padding: 16px 4vw; }
        }

        .img-card{
            background: #2a2665;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.07);
            max-width: 400px;
        }

        #programsTable {
            width: 100% !important;
            border-collapse: collapse;
            margin-top: 16px;
        }
        #programsTable th,
        #programsTable td {
            border: 1px solid #ccc;
            padding: 10px;
            font-size: 14px;
        }
        #programsTable th {
            background: #2a2665;
            color: #fff;
            text-align: center;
        }
        #programsTable td {
            text-align: center;
        }
        .total-summary {
            margin-top: 20px;
            font-size: 16px;
            font-weight: 600;
            text-align: right;
            color: #222;
        }
        .total-summary small {
            display: block;
            font-weight: normal;
            color: #555;
            margin-top: 4px;
        }
    </style>
</head>
<body>
    <div class="invoice-card">
        <div class="invoice-header">
            <div class="img-card">
                <img src="{{ public_path('logo.png') }}" alt="Logo" width="250">
            </div>
        </div>

        <hr class="divider">

        <table class="invoice-table" dir="rtl">
            <tr>
                <th>{{ __('trans.student.name') }}</th>
                <td>{{ $data['student']->name ?? '-' }}</td>
            </tr>
            <tr>
                <th>{{ __('trans.student.phone') }}</th>
                <td style="text-align: right" dir="ltr">{{ $data['student']->phone ?? '-' }}</td>
            </tr>
             <tr>
                <th>{{ __('trans.transaction.created_at') }}</th>
                <td>{{ $data->created_at->format('d/m/Y') ?? '-' }} - {{ $data->created_at->format('h:i A') }} </td>
            </tr>
        </table>

        {{-- <h4 style="text-align: center">{{ __('trans.program.title') }}</h4> --}}

        <div class="table-responsive px-4">
            <table class="table align-items-center mb-0 text-end" id="programsTable" dir="rtl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('trans.program.name') }}</th>
                        <th>{{ __('trans.program.category') }}</th>
                        <th>{{ __('trans.program.price') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($data->programs as $index => $program)
                        @php
                            if($data->status == 'completed') {
                                $subscription = App\Models\Subscription::where('student_id', $data->student_id)
                                ->where('program_id', $program->id)
                                ->first();
                            }
                        @endphp
                        <tr class="text-sm">
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $program->translations->firstWhere('locale', App()->getLocale())->title ?? '-' }}</td>
                            <td>{{ $program->category->translations->firstWhere('locale', App()->getLocale())->title ?? '-' }}</td>
                            <td>{{ $subscription->price }}</td>
                        </tr>
                    @endforeach
                    @php
                        $total = (float) ($data->total_price ?? 0);
                        $vatRate = 0.15; // 15%
                        // If total includes VAT, extract VAT portion: VAT = total * 15 / 115
                        $vatAmount = round($total * ($vatRate / (1 + $vatRate)), 2);
                        $subTotal = round($total - $vatAmount, 2);
                    @endphp
                    <tr>
                        <td colspan="3" style="font-weight: bold; text-align: right;">{{  'المجموع قبل الضريبة' }}</td>
                        <td colspan="1" style="font-weight: bold">{{ number_format($subTotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="font-weight: bold; text-align: right;">{{  'الضريبة المضافة (15%)' }}</td>
                        <td colspan="1" style="font-weight: bold">{{ number_format($vatAmount, 2) }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="font-weight: bold">{{ __('trans.transaction.total_price') }}</td>
                        <td colspan="1" style="font-weight: bold">{{ number_format($total, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- <div class="total-summary">
            {{ __('trans.transaction.total_price') }}: {{ $data->total_price ?? '-' }}
            <small>هذا المبلغ يشمل جميع البرامج المسجلة في هذه الفاتورة</small>
        </div> --}}
    </div>
</body>
</html>

