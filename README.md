## Instalação
Os arquivos e diretórios contidos neste repositório devem ficar disponíveis
para o Mapas Culturais em um diretório, como é feito com os demais plugins.
Seguindo as convenções do Mapas Culturais, o caminho do plugin seria
`/var/www/html/protected/application/plugins/LocationPatch`. Preenchido esse
requisito, o arquivo de configuração dos plugins deve conter uma entrada que
faz referência ao LocationPatch, como no exemplo a seguir.
```PHP
<?php
return [
    // entradas para os outros plugins
    // .
    // .
    // .
    "LocationPatch" => [
        "namespace" => "LocationPatch",
        "config" => [
            /**
             * Flag para habilitar o plugin.
             * Tipo: boolean
             */
            "enable" => env("LOCATION_PATCH_ENABLE", false),
            /**
             * Ponto de corte para seleção das entidades. São apenas consideradas
             * as entidades atualizadas antes deste timestamp.
             * Formato: yyyyMMddHHmmss
             */
            "cutoff" => env("LOCATION_PATCH_CUTOFF", "19800101000001"),
        ],
    ],
];
```
Normalmente esse arquivo de configuração fica em
`/var/www/html/protected/application/conf/conf-common.d/plugins.php`.
