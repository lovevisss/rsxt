<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>工作人员个人信息表</title>
    <style>
        @if($forPdf)
        @@font-face { font-family: WenQuanYi; src: url('{{ $fontUri }}') format('truetype'); }
        @endif
        @page { size: A4; margin: 13mm 12mm; }
        body { margin: 0; color: #243e38; font-family: WenQuanYi, "Microsoft YaHei", sans-serif; font-size: 10pt; }
        .toolbar { padding: 14px 20px; background: #edf3ec; text-align: right; }
        .toolbar button { border: 0; border-radius: 6px; background: #184b43; color: #fff; padding: 9px 18px; cursor: pointer; }
        .sheet { max-width: 900px; margin: 0 auto; }
        h1 { margin: 0 0 13px; text-align: center; color: #173f38; font-size: 20pt; font-weight: normal; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 13px; }
        .summary td { padding: 7px 9px; border: 1px solid #d9e4da; vertical-align: middle; }
        .summary .name { font-size: 15pt; font-weight: normal; }
        .summary .muted { color: #71867a; font-size: 8pt; }
        .summary .photo-cell { width: 28mm; text-align: center; padding: 4px; }
        .summary img { width: 25mm; height: 32mm; object-fit: cover; }
        h2 { margin: 15px 0 7px; padding: 5px 8px; border-left: 3px solid #31715b; background: #eaf2eb; color: #19483f; font-size: 11pt; font-weight: normal; page-break-after: avoid; }
        .fields { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .fields tr { page-break-inside: avoid; }
        .fields td { width: 50%; padding: 5px 7px; border: 1px solid #e1e9e1; vertical-align: top; line-height: 1.4; word-wrap: break-word; }
        .field-label { color: #70877b; font-size: 8pt; display: block; margin-bottom: 2px; }
        .field-value { color: #243e38; }
        .field-value.empty { color: #9cab9f; }
        .foot { margin-top: 13px; border-top: 1px solid #dce6dc; padding-top: 6px; color: #86998d; font-size: 8pt; }
        @unless($forPdf)
        @media screen { body { background: #f5f7f3; } .sheet { background: white; padding: 30px 34px; box-shadow: 0 12px 40px #203d3612; } }
        @endunless
        @media print { .toolbar { display: none; } .sheet { box-shadow: none; padding: 0; } }
    </style>
</head>
<body>
@unless($forPdf)
    <div class="toolbar"><button type="button" onclick="window.print()">打印个人信息</button></div>
@endunless
<main class="sheet">
    <h1>工作人员个人信息表</h1>
    <table class="summary"><tr>
        <td><div class="muted">姓名</div><div class="name">{{ $identity['h5001'] ?? '未填写' }}</div></td>
        <td><div class="muted">职工编号</div>{{ $identity['h5002'] ?? '未填写' }}<br><span class="muted">{{ $identity['h5003'] ?? '未填写部门' }}</span></td>
        <td class="photo-cell">
            @if($photoDataUri)<img src="{{ $photoDataUri }}" alt="职工照片">@else<span class="muted">暂无照片</span>@endif
        </td>
    </tr></table>
    @foreach($sections as $section)
        <h2>{{ $section['title'] }}</h2>
        <table class="fields">
            @foreach(array_chunk($section['fields'], 2, true) as $pair)
                <tr>
                    @foreach($pair as $key => $label)
                        @php($fieldValue = $values[$key] ?? null)
                        <td><span class="field-label">{{ $label }}</span><span class="field-value {{ $fieldValue === null || trim($fieldValue) === '' ? 'empty' : '' }}">{{ $fieldValue === null || trim($fieldValue) === '' ? '未填写' : $fieldValue }}</span></td>
                    @endforeach
                    @if(count($pair) === 1)<td></td>@endif
                </tr>
            @endforeach
        </table>
    @endforeach
    <div class="foot">资料来源：现有人事系统 · 个人档案只读导出</div>
</main>
</body>
</html>
