<?php

namespace Craft; 
require craft()->path->getConfigPath().'elements/UniversalTransformer.php';

function getEntryByRequestUri($uri)
{
    // Look up redirects
    $redirect = craft()->sproutSeo_redirects->findUrl($uri);
    if(!is_null($redirect) && ($redirect->method == 301 || $redirect->method == 302)){

        $data = [
            "type"=>"redirect",
            "method" => $redirect->method,
            "location" => $redirect->newUrl,
        ];
        JsonHelper::sendJsonHeaders();
        HeaderHelper::setHeader([
            'status' => $redirect->method,
            'Location' => $redirect->newUrl,
        ]);
        $output = json_encode($data, JSON_FORCE_OBJECT);
        echo $output;
        craft()->end();
    }

    // remove the first trailing slash
    $trimmedUri = ltrim($uri, '/');
    $element = craft()->elements->getElementByUri($trimmedUri);
    if ($element === null) {
        return false;
    }

    // Look Up Entry
    $entry = craft()->entries->getEntryById($element->id);
    if ($entry == null) {
        return false;
    }
    return ["section" => $entry->section->handle, "id" => $entry->id];
}

$endpoints = [
    "page.json" => function() {
        $uri = craft()->request->getQuery("uri");
        $entry = getEntryByRequestUri($uri);
        $criteria = $entry ? $entry : ["slug" => "notfound"];
        return [
            "description" => "This is a universal transformer. Just enter the channel slug",
            "criteria" => $criteria,
            "first" => true,
            "transformer" => 'Craft\UniversalTransformer',
        ];
    },
    "<section:{slug}>/<slug:{slug}>.json" => function($section,$slug) {
        return [
            "description" => "This is a universal transformer. Just enter the channel slug",
            "criteria" => [
                "section" => $section,
                "slug" => $slug,
            ],
            "first" => true,
            "transformer" => 'Craft\UniversalTransformer',
        ];
    },
    "<section:{slug}>.json" => function($section) {
        $criteria = [
            "section" => $section,
        ];
        $related = craft()->request->getQuery("relatedTo");
        if($related){
            $criteria["relatedTo"] = ["targetElement" => $related];
        }
        return [
            "description" => "This is a universal transformer. Just enter the channel slug",
            "criteria" => $criteria,
            "transformer" => 'Craft\UniversalTransformer',
        ];
    },
];


