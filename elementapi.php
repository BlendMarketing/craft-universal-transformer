<?php
namespace Craft;

//Load $endpoints
require craft()->path->getConfigPath().'elements/elements.php';

return [
    "defaults" => [
        "elementsPerPage" => 30,
        "elementType" => "Entry",
        "cache" => true,
    ],
    "endpoints" => $endpoints,
];

