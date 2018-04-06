<?php
namespace Craft;

require craft()->path->getPluginsPath().'elementapi/vendor/autoload.php';

use League\Fractal\TransformerAbstract;

class UniversalTransformer extends TransformerAbstract
{
    private $depth,$isPageBuilder;

    public function transform(EntryModel $entry)
    {
        //Deterine wether page is templated or page builder
        $this->isPageBuilder = $entry->section->handle === "pages";
        $this->depth = 0;

        $data = @$this->EntryTransformer($entry);

        return $data;
    }

    public function BaseObjectTransform($object)
    {
        //Get fields for object
        if(isset($object->type)){
            $fieldLayout = $object->type->getFieldLayout();
        }else{
            //Some Base Objects don't have types
            $fieldLayout = $object->getFieldLayout();
        }
        $tabs = $fieldLayout->getTabs();
        $fields = [];
        //Flatten fields to one array
        foreach($tabs as $tab){
            $fields = array_merge($fields,$tab->getFields());
        }
        //Begin processing Data
        $fieldData = [];
        foreach($fields as $fieldContainer){
            $field = $fieldContainer->field;
            $fieldData[$field->handle] = [
                "type" => $field->type,
                "settings" => $field->settings,
                "data" => $object[$field->handle],
            ];
        }
        //Assemble Data
        $data = [];
        foreach($fieldData as $handle => $field){
            //If page is not page built, then no need to process page build fields
            if(!$this->isPageBuilder && $handle == "pageBuilder"){
                continue;
            }
            $data[$handle] = null;
            if(!is_null($field['data'])){
                $transformerName = $field['type'] . "Transformer";
                //Determin if this is type Element Criteria
                if(gettype($field['data']) == "object"){
                    if(get_class($field['data']) == "Craft\ElementCriteriaModel"){
                        $transformerName = "ElementsCriteriaTransformer";
                    }
                }
                $data[$handle] = $this->$transformerName($field['data'],$field['settings']);
            }

        }
        return $data;
    }


    public function AssetTransformer($data){
        //For now just return url
        $assetService = new \Craft\AssetsService;
        $transforms = ["small","medium","large","wide"];
        $srcset = [];
        
        $assets["src"] =  $assetService->getUrlForFile($data,"small");

        //If image, set srcset
        $assetType = explode("/",$data->mimeType)[0];
        $assets["alt"] = is_null($data["altText"]) ? "" : $data["altText"];

        if($assetType != "image"){
            return $assets;
        }
        
        $imageType = explode("/",$data->mimeType)[1];
        
        if($imageType == "svg+xml"){
            return $assets;
        }

        foreach($transforms as $size){
            $transform = [];
            $transform["src"] = $assetService->getUrlForFile($data,$size);
            $transform["width"] = $data->getWidth($size);
            $transform["height"] = $data->getHeight($size);
            $srcset[] = $transform;
        }
        $assets["srcset"] = $srcset;

        return $assets; 
    }
    public function CategoryTransformer($data){
        return $data;
    }
    public function CheckboxesTransformer($data){
        return $data;
    }
    public function PlainTextTransformer($data, Array $settings){
        return $data;
    }
    public function DateTransformer(DateTime $data, Array $settings){
        //var_dump($data);
        //var_dump($settings);
        //die;
    }
    public function RichTextTransformer(RichTextData $data, Array $settings){
        return $data->getParsedContent();
    }
    public function SproutSeo_ElementMetadataTransformer($data){
        $meta = [];
        $meta["title"] = $data->title;
        $meta["desc"] = $data->description;
        $meta["keywords"] = $data->keywords;
        return $meta;
    }
    public function DropdownTransformer(SingleOptionFieldData $data, Array $settings){
        if(!$data->selected){
            return false;
        }
        return $data->value;
    }
    public function TableTransformer($data){
        return $data;
    }

    public function NumberTransformer($data){
        return $data;
    }

    public function LightswitchTransformer($data){
        return $data;
    }

    public function UserTransformer(UserModel $user){
        $baseUserData = [
            "fullName" => $user->fullName,
            "firstName" => $user->firstName,
            "lastName" => $user->lastName
        ];
        $data = $this->BaseObjectTransform($user);
        $data = array_merge($baseUserData,$data);
        return $data;
    }
    public function TagTransformer(TagModel $data){
        return $data->title;
    }
    //Different than the initial entry point since we don't want to go all the way down an infinite rabbit hole. Purposely leave it at the basics
    public function EntryTransformer(EntryModel $entry){
        $data =  [
            'id' => $entry->id,
            'slug' => $entry->slug,
            'title' => $entry->title,
            'url' => $entry->uri ? "/{$entry->uri}" : null,
        ];
        $data['type'] = $entry->type->handle;


        // Have to prevent infinite recurssion.
        // 2 Seems like a good default
        if($this->depth > 2){
            if( !in_array($data['type'],["contentSection","videoEmebds"]) || $this->depth > 3){
                return $data;
            }
        }

        // Increment here as this code my call itself eventually
        $this->depth++;
        $data = array_merge($data,$this->BaseObjectTransform($entry));
        // Reset since this is run for each field
        $this->depth--;
        return $data;
    }
    public function MatrixBlockTransformer(MatrixBlockModel $block){
        $data = [];
        $data['type'] = $block->type->handle;
        $blockData =  $this->BaseObjectTransform($block);
        //Manipulate Content Blocks
        $data = array_merge($data,$blockData);
        if($this->isPageBuilder){
            $data = $this->parseContentBlock($data);
        }
        return $data;
    }

    public function ElementsCriteriaTransformer(ElementCriteriaModel $elements, Array $settings){

        $isSingleEntry = false;
        if(isset($settings['limit']) && $settings['limit'] == 1){
            $isSingleEntry = true;
        }

        $data = [];
        foreach($elements as $element){
            $type = $element->elementType;
            $transformer = $type . "Transformer";
            $data[] = $this->$transformer($element);
        }

        if($this->isPageBuilder){
            $data = $this->parseSections($data);
        }

        if(count($data) < 1){
            return $isSingleEntry ? null : [];
        }
        if($isSingleEntry){
            return $data[0];
        }
        return $data;
    }

    public function parseCriteria($queryString,$entry)
    {

    }
    public function parseCtas($links){
        $ctas = [];
        foreach($links as $link){
            if(!isset($link['type'])){
                return [];
            }
            switch($link['type']){
            case "externalLink":
                $ctas[] = [
                    "text" => $link['linkText'],
                    "to" => $link['linkDestination'],
                ];
                break;
            case "internalLink":
                $href = isset($link['linkDestination']['url']) ? $link['linkDestination']['url'] : "";
                $ctas[] = [
                    "text" => $link['linkText'],
                    "to" => $href,
                ];
                break;
            }
        }
        return $ctas;
    }

    public function parseSections(Array $data)
    {

        //Check if Sections were used, replay them to combine
        $sectionData = [];
        $isSection = false;
        foreach($data as $key => $datum){
            if(!isset($datum['type'])){
                continue;
            };
            if($datum['type'] == "sectionStart"){
                $isSection = true;
            }
            if($isSection){
                $sectionData[] = $datum;
                unset($data[$key]);
            }
            if($datum['type'] == "sectionEnd"){
                //Strip the first and last items off, since it's meta
                $meta = array_merge(array_pop($sectionData),array_shift($sectionData));
                $header = $meta['header'];;
                $centered = $meta['centered'];;
                $background = $meta['background'];;
                unset($meta['type']);
                unset($meta['background']);
                unset($meta['header']);
                
                $section = [
                    "type" => "section",
                    "header" => $header,
                    "background" => $background,
                    "centered" => $centered,
                    //"meta" => $meta,
                    "data" => $sectionData,
                ];
                if($section['background'] === []){
                    unset($section['background']);
                }
                $data[$key] = $section;
                //Make sure everything is in proper order
                ksort($data);
                $sectionData = [];
                $isSection = false;
            }
        } 
        //reset indexes so transformer doesn't include indexes
        $data = array_values($data);
        return $data;
    }

    public function parseContentBlock(Array $block){
        $data = [
            "type"=> $block['type'],
        ];
        switch ($block['type']) {
        case "text":
            $data['text'] = "";
            if($block['inlineBody']){
                $data['text'] = $block['inlineBody'];
                $data['header'] = $block['inlineHeader'];
                return $data;
            }
            if($block['relatedBody']){
                if(isset($block['relatedBody']['body'])){
                    $data['text'] = $block['relatedBody']['body'];
                }
                if(isset($block['relatedBody']['header'])){
                    $data['header'] = $block['relatedBody']['header'];
                }
                if(count($block['relatedBody']['links']) > 0){
                    $data['ctas'] =  @$this->parseCtas($block['relatedBody']['links']);
                }     
                return $data;
            }
            
            return $data;
        case "video":
            $data["video"] = ["source"=>"youtube"];
            if($block['relatedVideo'] && $block['relatedVideo']['embeddedVideo']){
                $data['video']["id" ] = $block['relatedVideo']['embeddedVideo']['videoId'];
                $data["image" ] = $block['relatedVideo']["images"][0];
                return $data;
            }
            return $data;
        case "contentOnImage":
            if($block['contentBlock']){
                $content = $block['contentBlock'];
                $data["image"] = $content['supportingImage'];
                $data["header"] = $content['header'];
                $data["text"] = $content['body'];
                if(count($content['links']) > 0){
                    $data['ctas'] =  @$this->parseCtas($content['links']);
                }
            }
            return $data;
        case "form":
            $data["url"] = $block["form"]["formEmbedUrl"];
            $data["height"] = $block["form"]["formHeight"];
            $data["title"] = $block["form"]["title"];
            $data["header"] = $block["form"]["header"];
            $data["body"] = $block["form"]["body"];
            return $data;
        case "cta":
            $data["icon"] = $block["cta"]["icon"];
            $data["primaryText"] = $block["cta"]["primaryText"];
            $data["secondaryText"] = $block["cta"]["secondaryText"];
            return $data;
        case "images":
            $data["isFeatured"] = $block["isFeatured"] === "1";
            $data["images"] = [];
            if($block['inlineImage']){
                $data["images"] = $block['inlineImage'];
                return $data;
            }
            if($block['relatedImage']){
                $data["images"] = $block['relatedImage']["images"];
                return $data;
            }
            return $data;
        case "widget":
            break;
        case "sectionStart":
            $data['header'] = [
                "header" => $block['header'],
            ];
            if($block["centered"] !== ""){
                $data["centered"] = (bool) $block['centered'];
            }
            if($block["preHeading"] !== ""){
                $data["header"]["preHeading"] = $block['preHeading'];
            }
            if($block["subHeading"] !== ""){
                $data["header"]["subHeading"] = $block['subHeading'];
            }
            $data['background'] = [];
            if($block["backgroundImage"] && count($block["backgroundImage"]) !== 0){
                $data["background"]["image"] = $block['backgroundImage'];
            }elseif($block["backgroundPattern"] && $block["backgroundPattern"] != "none"){
                $data["background"]["pattern"] = $block['backgroundPattern'];
            }elseif($block["backgroundColor"] && $block["backgroundColor"] != "white"){
                $data["background"]["color"] = $block['backgroundColor'];
            }

            return $data;
        case "media":
            $ctas = @$this->parseCtas($block['relatedContent']['links']);
            $data = [
                "text" => isset($block['relatedContent']['body']) ? $block['relatedContent']['body'] : null,
                "header" => isset($block['relatedContent']['header']) ? $block['relatedContent']['header'] : null,
                "mediaType" => $block['mediaType'],
                "mediaLocation" => $block['mediaLocation'],
                "wide" => $block['wideColumn'],
                "type"=> $block['type'],
                "ctas" => $ctas,
            ];
            if(!isset($block['relatedContent']['images'][0])){
                return $data;
            }
            if($data['wide'] == "neither"){
                unset($data['wide']);
            }
            if($data['mediaType'] == "image"){
                $data['media'] = $block['relatedContent']['images'][0];
            }elseif($data['mediaType'] == "video"){
                $data['media'] = [
                    "id" =>  $block['relatedContent']['embeddedVideo']['videoId'],
                    "source" =>  $block['relatedContent']['embeddedVideo']['videoSource'],
                    "thumbnail" => $block['relatedContent']['images'][0],
                ];
            }

            return $data;
        case "list":
            $data['listType'] = $block['listType'];
            $listData = [];
            if($block['inlineList']){
                $listData = $block['inlineList'];
            }else{
                if(isset($block['relatedList']) && isset($block['contentBlocks'])){
                    $listData = $block['relatedList']['contentBlocks'];
                }
            }
            if($listData){
                $data['listData'] = $this->parseListData($data['listType'],$listData);
            }

            return $data;
        default:
            $data = array_merge($data,$block);
            return $data;
        }

        return $data;
    }

    public function parseListData($listType, $listData){

        $data = [];

        foreach($listData as $listItem){

            switch ($listType) {
            case "contentCards":
                $data[] = [
                    "header" => $listItem["header"],
                    "text" => $listItem["body"],
                    "ctas" => @$this->parseCtas($listItem['links']),
                ];
                break;
            case "imageCards":
                if($listItem["images"]){
                    $data[] = [
                        "header" => $listItem["header"],
                        "text" => $listItem["body"],
                        "images" => $listItem["images"],
                        "ctas" => @$this->parseCtas($listItem['links']),
                    ];
                }
                break;
            case "videoCards":
                if($listItem["youtubeId"]){
                    $data[] = [
                        "header" => $listItem["header"],
                        "text" => $listItem["body"],
                        "images" => $listItem["images"],
                        "video" => [
                            "id" => $listItem["youtubeId"],
                            "source" => "youtube",
                        ]
                    ];
                }
                break;
            }
        }
        return $data;
    }
}
