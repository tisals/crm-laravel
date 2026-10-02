<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Decreto 768/2022 Risk Matrix (PHP-side mirror)
    |--------------------------------------------------------------------------
    |
    | The CIIU -> ARL risk class + economic sector mapping that drives the
    | enrichment pipeline (PR1) and the Python FastMCP server (PR2). This
    | file MUST be byte-identical in semantic content to its Python mirror
    | at `mcp_server/resources/decreto_768.json`; see the SHA256 drift check
    | in `.github/workflows/ci.yml`.
    |
    | Shape of each entry:
    |   '6202' => [
    |       'clase_riesgo_ul_num' => 3,        // 1..5 (I..V)
    |       'clase_riesgo_ul_desc' => 'Medio', // Primario|Secundario|Terciario|...
    |       'sector_economico'    => 'Servicios',
    |       'fuente'              => 'decreto_768/2022',
    |   ],
    |
    | PR1 ships an empty matrix — the canonical Decreto 768 rows land in
    | PR6 via the Decreto768Seeder (which loads `database/csv/decreto_768.csv`)
    | and the matching Python matrix file is mirrored at the same time.
    |
    */

    'entries' => [
        //
    ],

];