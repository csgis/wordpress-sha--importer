<?php


namespace shap_datasource {

    use esa_item\image;

    class shap_easydb extends abstract_datasource {

        public $debug = false;

        public $force_curl = true;

        private $_session_token;

        private $_easydb_url = "";
        private $_easydb_user = "";
        private $_easydb_pass = "";

        function __construct() {
            $this->_easydb_url  = get_option('shap_db_url');
            $this->_easydb_user = get_option('shap_db_user');
            $this->_easydb_pass = get_option('shap_db_pass');
        }

        function dependency_check() {
            if (!$this->check_for_curl()) {
                throw new \Exception('PHP Curl extension not installed');
            }
            $this->get_easy_db_session_token();
            return 'O. K.';
        }

        function parse_error_response($msg) {
            $json_msg = json_decode($msg);
            if (is_object($json_msg)) {
                return isset($json_msg->description) ? $json_msg->description : $json_msg->code;
            }
            return $msg;
        }

        function get_easy_db_session_token() {
            if ($this->_session_token) {
                return $this->_session_token;
            }
            try {
                $resp = json_decode($this->_fetch_external_data("{$this->_easydb_url}/api/v1/session"));
                if (!isset($resp->token)) {
                    throw new \Exception('no token');
                }
                $this->_session_token = $resp->token;
            } catch (\Exception $e) {
                throw new \Exception('Easy-DB: create session failed: ' . $this->parse_error_response($e));
            }
            try {

                if (!$this->_easydb_url or !$this->_easydb_pass or !$this->_easydb_user) {
                    $credentials = "method=anonymous";
                } else {
                    $credentials = "login={$this->_easydb_user}&password={$this->_easydb_pass}";
                }

                $this->_fetch_external_data((object) array(
                    "url" => "{$this->_easydb_url}/api/v1/session/authenticate?token={$this->_session_token}&$credentials",
                    "method" => "post"
                ));
            } catch (\Exception $e) {
                throw new \Exception('Easy-DB: authentication failed: ' . $this->parse_error_response($e));
            }
            return $this->_session_token;
        }

        function api_single_url($object_id, $params = array()) : string {
            $this->get_easy_db_session_token();
            return "{$this->_easydb_url}/api/v1/db/bilder/bilder__all_fields/global_object_id/$object_id@local?token={$this->_session_token}";
        }

        function api_place_url($object_id) {
            return "{$this->_easydb_url}/api/v1/db/ortsthesaurus/ortsthesaurus__l/global_object_id/$object_id@local?token={$this->_session_token}";
        }

        function api_record_url($id, $params = array()) : string {
            return "{$this->_easydb_url}/lists/bilder/id";
        }

        function api_search_url($query, $params = array()) {

            $this->get_easy_db_session_token();

            $search = array(
                "limit" => $this->items_per_page,
                "objecttypes" => array("bilder"),
	            "generate_rights" => false
            );

            if (!in_array($query, array("", "*"))) {
                $search["search"] = array(
                    array(
                        "type" => "match",
                        "mode" => "token",
                        "string"=> $query,
                        "phrase"=> true
                    )
                );
            }

            if (isset($params['offset'])) {
                $search['offset'] = $params['offset'];
            }


            return (object) array(
                'method' => 'post',
                'url' => "{$this->_easydb_url}/api/v1/search?token={$this->_session_token}",
                'post_json' => $search
            );
        }

        function api_search_url_next($query, $params = array()) {
            $this->page += 1;
            $params['offset'] = ($this->page - 1) * $this->items_per_page;
            return $this->api_search_url($query, $params);
        }

        function api_search_url_prev($query, $params = array()) {
            $this->page -= 1;
            $params['offset'] = ($this->page - 1) * $this->items_per_page;
            return $this->api_search_url($query, $params);
        }

        function api_search_url_first($query, $params = array()) {
            $this->page = 1;
            $params['offset'] = ($this->page - 1) * $this->items_per_page;
            return $this->api_search_url($query, $params);
        }

        function api_search_url_last($query, $params = array()) {
            $this->page = $this->pages;
            $params['offset'] = ($this->page - 1) * $this->items_per_page;
            return $this->api_search_url($query, $params);
        }

        function parse_result_set($response) : array {
            $response = json_decode($response);
            $this->results = array();
            foreach ($response->objects as $item) {
                $this->results[] = $this->parse_result($this->_fetch_external_data($this->api_single_url($item->_system_object_id)));
            }

            $this->pages = (int) ($response->count / $this->items_per_page) + 1;
            $this->page = isset($response->offset) ? ((int) ($response->offset / $this->items_per_page) + 1) : 1;

            return $this->results;
        }

        function parse_result($response) {

            $json_response = $this->_json_decode($response);
            $system_object_id = $json_response[0]->_system_object_id;
            $object_type = $json_response[0]->_objecttype;
            $object = $json_response[0]->{$object_type};

            $title = "Image #" . $system_object_id;

//            if ($object_type !== "bilder") {
//                return new \esa_item("shap_easydb", $system_object_id, "not in bilder: $object_type", $this->api_record_url($system_object_id), "error");
//            }
//
//            $this->_parse_title($object, $data);
//            $this->_parse_blocks($object, $data);
//            $this->_parse_nested($object, $data);
//            $this->_parse_date($object, $data);
//            $this->_parse_pool($object, $data);
//            $this->_parse_tags($json_response[0], $data);
//
//            list($lat, $lon) = $this->_parse_place($object, $data);
//
//            // images
//            if (isset($object->bild) and isset($object->bild[0]->versions)) {
//
//                $image = array();
//                $image['url']   = $object->bild[0]->versions->small->url;
//
//                $versions = array_filter((array) $object->bild[0]->versions, function($v) {
//                    return  ($v->status !== "failed") && (!$v->_not_allowed);
//                });
//
//                if (isset($versions['full'])) {
//                    $image['fullres']   = $versions->full->url;
//                } else if (isset($versions['original'])) {
//                    $image['fullres']   = $versions->original->url;
//                }
//                $image['title'] = $object->bild[0]->original_filename;
//
//
//                $image = new \esa_item\image($image);
//            }
//
//            $html = $image->render();
//
//            if (isset($object->copyright_vermerk) and is_string($object->copyright_vermerk)) {
//                $html .= "<div class='esa_shap_subtext'>{$object->copyright_vermerk}</div>";
//            } else if (isset($object->copyright_vermerk) and is_object($object->copyright_vermerk)) {
//                $en = "en-US";
//                $html .= "<div class='esa_shap_subtext'>{$object->copyright_vermerk->$en}</div>";
//            }

            return $title;
        }



        function _parse_title($o, \esa_item\data $data) {
            $en = "en-US";
            if (isset($o->ueberschrift)) {
                $data->title = $o->ueberschrift->$en;
            } else if (isset($o->titel)) {
                $data->title = $o->titel->$en;
            } else if (isset($o->beschreibung)) {
                $data->title = $o->beschreibung->$en;
            }
        }

        function _parse_pool($o, \esa_item\data $data) {
            if ($o->_pool->pool->_id == 1) {
                return;
            }

            $data->putMultilang("pool", (array) $o->_pool->pool->name);
        }

        function _parse_nested($o, \esa_item\data $data) {

            $to_parse = array(
                "keyword"   =>  "schlagwort",
                "element"   =>  "element",
                "style"     =>  "stilmerkmal",
                "tech"      =>  "technik",
                "material"  =>  "material",
            ); // skipped: teilelement, literatur

            foreach ($to_parse as $tag_type => $name) {
                $n = "_nested:bilder__$name";
                $a = "lk_{$name}_id";
                foreach ($o->$n as $keyword) {
                    $this->_get_detail($data, $tag_type, $keyword->$a);
                }
            }
        }

        function _parse_date($o, \esa_item\data $data) {
            if (isset($o->original_datum)) {
                $data->put("decade", $this->_get_decade($o->original_datum->_from));
            } else if (isset($o->bild[0]->date_created)) {
                if (isset($o->bild) and count($o->bild)) {
                    $data->put("decade", $this->_get_decade($o->bild[0]->date_created));
                }
            }
        }

        function _parse_blocks($o, \esa_item\data $data) {
            $blocks = array(
                "template"  => "art_der_vorlage_id",
                "state"     => "bearbeitungsstatus_id",
                "motive"    => "art_des_motivs_id_old",
                "place"     => "ort_des_motivs_id",
                "provider"  => "anbieter_id",
                "creator"   => "ersteller_der_vorlage_id_old",
                "material"  => "material_der_vorlage_id"
            );

            foreach ($blocks as $bname => $block) {
                $this->_get_detail($data, $bname, $o->$block);
            }
        }

        function _get_decade(string $datestring) {
            $year = date("Y", strtotime($datestring));
            return substr($year, 0, 3) . "0s";
        }

        function _get_detail($data, $name, $block, $field = "_standard") {
            $one = 1;
            if (isset($block->$field) and isset($block->$field->$one)) {
                $data->putMultilang($name, (array) $block->$field->$one->text);
            }
        }

        function _parse_place($o, \esa_item\data $data) : array {

            if (!isset($o->ort_des_motivs_id)) {
                return array(null, null);
            }

            $soid = $o->ort_des_motivs_id->_system_object_id;

            $place = json_decode($this->_fetch_external_data($this->api_place_url("$soid")));

            $place = $place[0];

            if (!isset($place) or !isset($place->ortsthesaurus) or !isset($place->ortsthesaurus->gazetteer_id)) {
                return array(null, null);
            }

            $gazId = $place->ortsthesaurus->gazetteer_id;

            foreach ($gazId->otherNames as $name) {
                $data->put("place", $name->title, "#");
            }

            if (!isset($gazId->position)) {
                return array(null, null);
            }

            return array($gazId->position->lat, $gazId->position->lng);
        }

        function _parse_tags($o, \esa_item\data $data) {
            $import_tags = array(
                31  => "article_image",
                4   => "aleppo access",
                16  => "in process",
                19  => "accessible",
                25  => "destroyed"
            );

            $used_tags = array_map(function($t) {return $t->_id;}, $o->_tags);

            foreach ($used_tags as $tag) {
                if (isset($import_tags[$tag])) {
                    $data->put("tag", $import_tags[$tag]);
                }
            }

        }

        function stylesheet() {
            return array(
                'file' => plugins_url(ESA_DIR . '/plugins/shap_easydb/esa_shap.css'),
                'name' => "shap"
            );
        }

    }
}
?>