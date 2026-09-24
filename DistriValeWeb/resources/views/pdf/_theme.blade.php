<style>
    /*
     * Shared PDF theme for every report generated with dompdf in this app.
     * Mirrors the web UI's palette (accent blue #4f7cff, near-black text,
     * rounded "glass card" sections) so printed reports read as the same
     * product as the screens they're exported from. Include this partial
     * inside a report's <head> and reuse the .pdf-* classes below instead
     * of ad-hoc styles, so future reports stay visually consistent.
     */
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #101828; margin: 24px; }

    .pdf-title-bar {
        background: #4f7cff; color: #fff; border-radius: 14px;
        padding: 14px 20px; margin-bottom: 14px;
    }
    .pdf-title-bar h1 { font-size: 19px; margin: 0; }
    .pdf-title-bar .pdf-subtitle { font-size: 10.5px; opacity: .92; margin-top: 3px; }

    .pdf-card {
        border: 1.4px solid #4f7cff; border-radius: 14px;
        padding: 12px 20px; margin-bottom: 14px;
    }
    .pdf-card table.pdf-meta { width: 100%; border-collapse: collapse; }
    .pdf-card table.pdf-meta td { border: none; padding: 0 16px 6px 0; vertical-align: top; }
    .pdf-label {
        display: block; font-size: 8.5px; text-transform: uppercase; letter-spacing: .06em;
        color: #667085; font-weight: 700; margin-bottom: 2px;
    }
    .pdf-value { font-size: 13px; font-weight: 700; color: #101828; }

    .pdf-table-card { border: 1.4px solid #4f7cff; border-radius: 14px; padding: 2px; margin-bottom: 14px; }
    table.pdf-table { width: 100%; border-collapse: collapse; }
    table.pdf-table th {
        background: rgba(79, 124, 255, .14); color: #101828; text-align: left;
        text-transform: uppercase; font-size: 8.5px; letter-spacing: .04em; font-weight: 700;
        padding: 8px 10px; border-bottom: 1.4px solid #4f7cff;
    }
    table.pdf-table th:first-child { border-top-left-radius: 12px; }
    table.pdf-table th:last-child { border-top-right-radius: 12px; }
    table.pdf-table td { padding: 7px 10px; border-bottom: 1px solid #e3e8f0; font-size: 10px; color: #101828; }
    table.pdf-table tr:last-child td { border-bottom: none; }
    table.pdf-table tr.pdf-group-row td {
        background: rgba(79, 124, 255, .08); color: #3b5fd9; font-weight: 700;
        text-transform: uppercase; font-size: 9px; letter-spacing: .03em;
    }
    table.pdf-table tfoot td { font-weight: 700; background: rgba(79, 124, 255, .08); border-top: 1.4px solid #4f7cff; }

    .pdf-footer-note {
        background: #4f7cff; color: #fff; border-radius: 14px;
        padding: 11px 20px; text-align: center; font-size: 10px; font-weight: 600;
    }

    .pdf-muted { color: #667085; }
    .pdf-text-success { color: #1a9c6c; font-weight: 700; }
    .pdf-text-danger { color: #e14059; font-weight: 700; }

    .pdf-badge {
        display: inline-block; padding: 2px 9px; border-radius: 999px;
        font-size: 8.5px; font-weight: 700; text-transform: uppercase;
    }
    .pdf-badge-ACTIVO { background: rgba(43, 196, 138, .16); color: #1a9c6c; }
    .pdf-badge-EN_MORA { background: rgba(255, 159, 67, .18); color: #c97316; }
    .pdf-badge-LIQUIDADO { background: rgba(120, 130, 145, .16); color: #6b7688; }
</style>
