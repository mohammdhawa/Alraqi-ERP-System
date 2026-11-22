<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documents
|--------------------------------------------------------------------------
|
| docs/superpowers/specs/2026-11-22-documents-design.md.
|
| pdf.repair — what rewrites a PDF the printing cannot open (FPDI's free
| parser refuses compressed cross-reference streams, §11.5). The owner's
| answer of 2026-11-22: convert it on the server. `qpdf` is the default;
| `ghostscript` the alternative; `none` refuses every such file at upload.
| THE PRODUCTION SERVER MUST HAVE THE BINARY INSTALLED.
|
*/

return [
    'pdf' => [
        'repair' => [
            'driver'  => env('DOCUMENTS_PDF_REPAIR', 'qpdf'),
            'binary'  => env('DOCUMENTS_PDF_REPAIR_BINARY', env('DOCUMENTS_PDF_REPAIR', 'qpdf') === 'ghostscript' ? 'gs' : 'qpdf'),
            'timeout' => (int) env('DOCUMENTS_PDF_REPAIR_TIMEOUT', 60),
        ],
    ],
];
