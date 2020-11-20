<?php

/**
 * Description of scan_ip
 *
 * @author Ynats
 */

require_once __DIR__ . "/../../../../plugins/scan_ip/core/class/scan_ip.require_once.php";

class scan_ip_scan extends eqLogic {

    public static function syncScanIp(){
        log::add('scan_ip', 'debug', '////////////////////////////////////////////////////////////////////');
        log::add('scan_ip', 'debug', 'syncScanIp :. Lancement du scan du réseau');
        
        // Si json pas au bon endroit
        if(@is_file(__DIR__ . "/../../../../plugins/scan_ip/core/json/mapping.json")){
            shell_exec("sudo mv " . __DIR__ . "/../../../../plugins/scan_ip/core/json/*.json " . __DIR__ . "/../../../../plugins/scan_ip/data/json");
        }
        
        self::scanReseau();
        
        $jsonMapping = scan_ip_json::getJson(scan_ip::$_jsonMapping);
        
        $eqLogics = eqLogic::byType('scan_ip');
        foreach ($eqLogics as $scan_ip) {
            if ($scan_ip->getIsEnable() == 1) {
                log::add('scan_ip', 'debug', 'syncScanIp :. cmdRefresh('.$scan_ip->getId().')');
                scan_ip_cmd::cmdRefresh($scan_ip, $jsonMapping);
            }
        }  
        
        log::add('scan_ip', 'debug', 'syncScanIp :. Fin du scan du réseau');
        log::add('scan_ip', 'debug', '////////////////////////////////////////////////////////////////////');
    }
       
    public static function scanReseau(){
        log::add('scan_ip', 'debug', "////////////////////////////////////////////////////////////////////");
        log::add('scan_ip', 'debug', 'scanReseau :. Lancement');
        
        $ipRoute = scan_ip_shell::getIpRoute();  
        $subReseau = scan_ip_shell::getSubReseauEnable($ipRoute);  
        $infoJeedom = scan_ip_shell::getInfoJeedom($ipRoute);
        
        if($subReseau["subReseauEnable"] == 0) {
            config::save("sub_enable_".md5($subReseau["name_plage_route"]), 1, 'scan_ip');
            $subReseau = scan_ip_shell::getSubReseauEnable($ipRoute);
        }

        $new = array();
        foreach ($subReseau["subReseau"] as $sub) { 
            if($sub["enable"] == 1){
                $scanResult = scan_ip_shell::arpScanShell($sub["name"]); 
                $new = scan_ip_tools::arrayCompose($new, $scanResult);
            }
        }

        if(count($new) == 0){            
            log::add('scan_ip', 'error', "Aucun élément n'a été trouvé sur vos réseaux. Vérifiez vos configurations.");
            event::add('jeedom::alert', array(
                'level' => 'warning',
                'page' => 'scan_ip',
                'message' => "Aucun élément n'a été trouvé sur vos réseaux. Vérifiez vos configurations."
            )); 
        } 
        else {
            $equipement = self::createArchiveEquipement($new);
            $now = scan_ip_tools::arrayCompose($equipement, $new);       
            $now = scan_ip_tools::cleanArrayEquipement($now); 
            $nowMapping = self::createJsonMapping($now, $equipement, $ipRoute, $infoJeedom); 
        }
        
        $nowMapping["jeedom"] = $infoJeedom; 
        $nowMapping["infos"]["version_arp"] = scan_ip_shell::arpVersion();
        $nowMapping["infos"]["time"] = time();
        $nowMapping["infos"]["date"] = date("d/m/Y H:i:s", $nowMapping["infos"]["time"]);

        scan_ip_json::recordInJson(scan_ip::$_jsonMapping, $nowMapping);
        
        log::add('scan_ip', 'debug', 'scanReseau :. Fin du scan [' . $nowMapping["infos"]["version_arp"] . ']');
        log::add('scan_ip', 'debug', "////////////////////////////////////////////////////////////////////");
        
    }
    
    public static function createArchiveEquipement($_new){
        
        $old = scan_ip_json::getJson(scan_ip::$_jsonEquipement);
        
        if($old != NULL){
            
            foreach (array_merge($_new, $old) as $mac => $scanLine) {
                
                if(empty($scanLine["record"]) OR $scanLine["record"] == ""){ $return[$mac]["record"] = time(); } 
                else { $return[$mac]["record"] = $old[$mac]["record"]; }
                
                if(empty($old[$mac]["equipement"]) AND empty($_new[$mac]["equipement"]) OR ((!empty($old[$mac]["equipement"]) AND $old[$mac]["equipement"] == "...") AND (!empty($_new[$mac]["equipement"]) AND $_new[$mac]["equipement"] == "..."))){
                    $return[$mac]["equipement"] = scan_ip_api_mac_vendor::get_MacVendor($mac);
                }
                elseif(!empty($_new[$mac]["equipement"]) and $_new[$mac]["equipement"] != "..."){
                    $return[$mac]["equipement"] = $_new[$mac]["equipement"];
                }
                else {
                    $return[$mac]["equipement"] = $old[$mac]["equipement"]; 
                }   
                
                if(!empty($_new[$mac]["ip_v4"])){ $return[$mac]["ip_v4"] = $_new[$mac]["ip_v4"]; } 
                elseif(!empty($old[$mac]["ip_v4"])){ $return[$mac]["ip_v4"] = $old[$mac]["ip_v4"]; }
                else { $return[$mac]["ip_v4"] = "..."; }

                if(!empty($_new[$mac]["time"])){ $return[$mac]["time"] = $_new[$mac]["time"]; } 
                 elseif(!empty($old[$mac]["time"])){ $return[$mac]["time"] = $old[$mac]["time"]; }
                else { $return[$mac]["time"] = "..."; }
                
            }
        } else {
            foreach ($_new as $mac => $scanLine) {
                $return[$mac]["record"] = time();
                $return[$mac]["equipement"] = $scanLine["equipement"];
            }
        }
        
        scan_ip_json::createJsonFile(scan_ip::$_jsonEquipement, $return);
        
        return $return;
    }
    
    public static function createJsonMapping($_now, $_equipement, $_ipRoute, $_infoJeedom){
        
        $timeNow = time();
        $_return = array();
        $add_network_routeur = config::byKey('add_network_routeur', 'scan_ip', 0);
        $add_network_jeedom = config::byKey('add_network_jeedom', 'scan_ip', 0);
        
        if($add_network_jeedom == 1){
            $_jeedom[$_infoJeedom["mac"]] = array(
                "ip_v4" => $_infoJeedom["ip_v4"], 
                "equipement" => $_infoJeedom["name"], 
                "time" => $_infoJeedom["time"], 
                "record" => $_infoJeedom["record"]
            );
            $_now = scan_ip_tools::arrayCompose($_jeedom, $_now);
        }
          
            foreach ($_now as $mac => $scanLine) {
                
                if($scanLine["ip_v4"] == $_ipRoute){
                    $_return["route"]["ip_v4"] = $scanLine["ip_v4"];
                    $_return["route"]["mac"] = $mac;
                    $_return["route"]["equipement"] = $scanLine["equipement"];
                } 
                
                if($add_network_routeur == 1 OR $scanLine["ip_v4"] != $_ipRoute){
                
                    if(!empty($_equipement[$mac]["record"])){
                        $record = $_equipement[$mac]["record"];
                    } else {
                        $record = $_infoJeedom["record"];
                    }

                    $_return["sort"][] = array(
                            "record" => $record,
                            "ip_v4" => $scanLine["ip_v4"], 
                            "mac" => $mac, 
                            "time" => $scanLine["time"], 
                            "equipement" => $scanLine["equipement"]
                    );
                    $_return["byIpv4"][$scanLine["ip_v4"]] = array(
                        "mac" => $mac, 
                        "equipement" => $scanLine["equipement"], 
                        "time" => $scanLine["time"], 
                        "record" => $record
                        );
                    $_return["byMac"][$mac] = array(
                        "ip_v4" => $scanLine["ip_v4"], 
                        "equipement" => $scanLine["equipement"], 
                        "time" => $scanLine["time"], 
                        "record" => $record
                        );  
                    $_return["byTime"][$scanLine["time"].$record][] = array(
                        "time" => $scanLine["time"], 
                        "mac" => $mac, "ip_v4" => $scanLine["ip_v4"], 
                        "equipement" => $scanLine["equipement"], 
                        "record" => $record
                        );
                }
            }

            ksort($_return["sort"]);
            krsort($_return["byTime"]);
        
        return $_return;
    }
    
}