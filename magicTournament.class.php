<?php

// Represents a magic the gathering tournament...assumes player 1's perspective unless otherwise noted

require_once('config_db.php');

abstract class magicTournament{

  protected $session;
  protected $timestamp;
  protected $sets;            // Array of cardset codes that make up a player's cardpool
  protected $pack_sizes;
  protected $creature_count;
  protected $current_pack;
  
  // 4D Array for drafts (pack/player/card-position/card-details), 2D Array for sealed deck tournaments
  protected $cards;           
  protected $added_lands;
  
  // Generic accessors
    
  public function getTimestamp(){
  	return $this->timestamp;
  }
  
  public function getSessionID(){
  	return $this->session;
  }
  
  public function getAllCards(){
  	return $this->cards;
  }
  
  public function getCreatureCount(){
  	return $this->creature_count;
  }

  // Helper function to determine converted mana cost
  
  public function spellCMC($cost){
  	$cost = explode("/", $cost); // just use the first cost for split cards
  	// Next remove % which is code for 'either/or' colors...as well as XYZ which is for variable casting costs
  	$cost = str_replace("%", "", str_replace("X", "", str_replace("Y", "", str_replace("Z", "", $cost[0])))); 
    $cmc = 0;
    $last_num = 0;
    for($i=0;$i<strlen($cost);$i++){
    	if(is_numeric($cost[$i])){
    		if($last_num != 0) $cmc += ($last_num * 10) - $last_num;
    		$cmc += $cost[$i];
    		$last_num = $cost[$i];
    	}else{
    		$cmc++;
    	}
    }
    return (integer)$cmc;
  }
  
  // Pack creating helper function...returns an array of cards...will use cache if available
  // If cache is false, dont use the cache
  // pack_number is used for sets or formats where later packs are dependent on earlier packs (singleton drafts like cube)
  
  protected function crackPack($set, $cache=true, $pack_number=0){
  	
  	global $db;
  	
  	if($set == ""){
  		return array();
  	}
  	$sete = mysql_real_escape_string($set);

  	if($set == "XXX"){
  		
  		// Random set requested...get a list of sets
  		
  		$select = "/*72 " . $sete . "*/ SELECT code FROM cardsets WHERE code!='XXX' AND block !='Special' AND (commons + uncommons + rares + lands) = 15";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $all_sets = $db->sql_fetchrowset_assoc($id);
  		$random_index = rand(0, count($all_sets) - 1);
  		$set = $all_sets[$random_index]['code'];
  	}else if($set == "CUB"){
  		
  		// Cube requested...since each card in each pack cannot be duplicated, we need a cache specific to this session
  		
  		$seed = substr(ereg_replace('[^0-9]+', '', $this->getSessionID()), 0, 10);
  		mt_srand($seed);
  		$random_cube = mt_rand(1,200);
  		mt_srand($seed);
  		$random_pack = ($pack_number + mt_rand(1,24)) % 24;
  		if($random_pack == 0) $random_pack = 24;
  		//echo "c=" . $random_cube . " p=" . $random_pack . "s=" . $seed;
  		$file = "/home/mds/public_html/admin/cache/" . $set . "/" . $random_cube . "/" . $random_pack;
  		if(file_exists($file)){
  			$cards = unserialize(file_get_contents($file));
  			$this->pack_sizes[$this->current_pack] = count($cards);
  			return $cards;
  		}
  	}
  	
  	// See if there is a cached pack available, use one if there is
  	
  	$path = "/home/mds/public_html/admin/cache/" . $set;
  	if($cache && file_exists($path . "/4999")){
  		$rand = mt_rand(1,4999);
  		$cards = unserialize(file_get_contents($path . "/" . $rand));
  		$this->pack_sizes[$this->current_pack] = count($cards);
  		return $cards;
  	}
  	
  	// Gotta generate the pack...
  	// First determine if this set has mythics/foils 
  	
  	$select = "SELECT `order`, block, commons, uncommons, rares, mythics, foil, lands FROM cardsets WHERE code='" . mysql_real_escape_string($set) . "'";
    if ( !($result = $db->sql_query($select)) ) {					
      echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
    }
    $id = $db->query_result;  
    $set_details = $db->sql_fetchrow_assoc($id);
    
    // Deal w/ bad input
    
    if($set_details['order'] == ""){
      return array();
    }
    
    // Set the pack size
    
    if($set == "TSP" || $set == "PLC" || $set == "ISD" || $set == "DKA"){
    	$this->pack_sizes[$this->current_pack] = 15;
    }else{
      $this->pack_sizes[$this->current_pack] = $set_details['commons'] + $set_details['uncommons'] + $set_details['rares'] + $set_details['lands'];
    }

    // Determine random mythic/foil chances

    $mythic_chance = rand(1,8);
  	$foil_chance = rand(1,5);

    // Start building the pack, start w/ commons
  	
  	$return = array();
  	$select = "/*124 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards ";
  	$select .= "WHERE rarity='C' AND type NOT LIKE 'Basic%Land%' AND cardset='" . mysql_real_escape_string($set) . "' ";
  	if($set == "ME4"){
  		$select .= " AND type NOT LIKE '%Urza\'s%' ";
  	}
  	$select .= "ORDER BY RAND() LIMIT " . $set_details['commons'];
    if ( !($result = $db->sql_query($select)) ) {					
      echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
    }
    $id = $db->query_result;  
    $commons = $db->sql_fetchrowset_assoc($id);
    
    // Planar Chaos Special case
    
    if($set == "PLC"){
    	$select = "/*139 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='C' AND type NOT LIKE 'Basic%Land%' AND cardset='PCT' ORDER BY RAND() LIMIT 3";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $time_shifted_commons = $db->sql_fetchrowset_assoc($id);
      $commons = array_merge($time_shifted_commons, $commons);
    }
    
    // Innistrad double faced cards are special
    
    if($set == "ISD"){
    	$dfc_rarity = mt_rand(1,15);
    	if($dfc_rarity == 1){
    		$dfc_mythic = mt_rand(1,8);
    		if($dfc_mythic == 8){
    			$dfc_rarity = "M";
    		}else{
    		  $dfc_rarity = "R";
    		}
    	}else if($dfc_rarity < 5){
    		$dfc_rarity = "U";
    	}else{
    	  $dfc_rarity = "C";
      }
    	$select = "/*164 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='" . $dfc_rarity . "' AND cardset='DFC' ORDER BY RAND() LIMIT 1";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $double_faced_cards = $db->sql_fetchrowset_assoc($id);
      $commons = array_merge($double_faced_cards, $commons);
    }else if($set == "DKA"){
    	$dfc_rarity = mt_rand(1,15);
    	if($dfc_rarity == 1){
    		$dfc_mythic = mt_rand(1,8);
    		if($dfc_mythic == 8){
    			$dfc_rarity = "M";
    		}else{
    		  $dfc_rarity = "R";
    		}
    	}else if($dfc_rarity < 5){
    		$dfc_rarity = "U";
    	}else{
    	  $dfc_rarity = "C";
      }
    	$select = "/*185 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='" . $dfc_rarity . "' AND cardset='DF2' ORDER BY RAND() LIMIT 1";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $double_faced_cards = $db->sql_fetchrowset_assoc($id);
      $commons = array_merge($double_faced_cards, $commons);
    }
    
    // Now basic land(s) if needbe
    
    $lands = array();
    if($set_details['lands'] > 0){
    	$select = "/*198 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards ";
    	$select .= "WHERE rarity='C' AND cardset='" . mysql_real_escape_string($set) . "' ";
    	if($set == "ME4"){
    		$select .= "AND type LIKE '%Urza\'s%' ";
      }else{
        $select .= "AND type LIKE 'Basic%Land%' ";
      }
    	$select .= "ORDER BY RAND() LIMIT " . $set_details['lands'];
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $lands = $db->sql_fetchrowset_assoc($id);
      
      // Recent small expansions include land from their 'mother' set
      
      if(count($lands) == 0){
      	$select = "SELECT code FROM cardsets WHERE block='" . $set_details['block'] . "' ORDER BY `order` ASC LIMIT 1";
        if ( !($result = $db->sql_query($select)) ) {					
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        }
        $id = $db->query_result;  
        $mother_set = $db->sql_fetchrow_assoc($id);
      	$select = "/*221 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='C' AND type LIKE 'Basic%Land%' AND cardset='" . $mother_set['code'] . "' ORDER BY RAND() LIMIT " . $set_details['lands'];
        if ( !($result = $db->sql_query($select)) ) {					
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        }
        $id = $db->query_result;  
        $lands = $db->sql_fetchrowset_assoc($id);
      }
      
    	$commons = array_merge($lands,$commons);
    }
    
    // Uncommons
    
  	$select = "/*234 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='U' AND cardset='" . mysql_real_escape_string($set) . "' ORDER BY RAND() LIMIT " . $set_details['uncommons'];
    if ( !($result = $db->sql_query($select)) ) {					
      echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
    }
    $id = $db->query_result;  
    $uncommons = $db->sql_fetchrowset_assoc($id);
    
    // Another Planar Chaos special case
    
    if($set == "PLC"){
    	$timeshifted_rarity = "U";
    	$timeshifted_chances = rand(1,2);
    	if($timeshifted_chances == 2){
    		$timeshifted_rarity = "R";
    	}
    	$select = "/*249 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='" . $timeshifted_rarity . "' AND type NOT LIKE 'Basic%Land%' AND cardset='PCT' ORDER BY RAND() LIMIT 1";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $time_shifted_rare = $db->sql_fetchrowset_assoc($id);
      $uncommons = array_merge($uncommons, $time_shifted_rare);
    }
    
    // Rare or mythic
    
    $rare = array();
    $rarity = "R";
    if($mythic_chance == 8 && $set_details['mythics'] > 0){
    	$rarity = "M";
    }
	  $select = "/*265 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE rarity='" . $rarity . "' AND cardset='" . mysql_real_escape_string($set) . "' ORDER BY RAND() LIMIT " . $set_details['rares'];
    if ( !($result = $db->sql_query($select)) ) {					
      echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
    }
    $id = $db->query_result;  
    $rare = $db->sql_fetchrowset_assoc($id);
  	
  	// Time Spiral Special case
  	
  	if($set == "TSP"){
  	  $select = "/*275 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE cardset='TSB' ORDER BY RAND() LIMIT 1";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $timeshifted_rare = $db->sql_fetchrowset_assoc($id); 
      $rare = array_merge($rare, $timeshifted_rare); 		
  	}
  	
  	// Deal w/ Foils
  	
  	$foil = array();
  	if($set_details['foil'] == 1 && $foil_chance == 5){
  		$total_chances = 120;
  		if($set_details['mythics'] = 1){
  			$total_chances++;
  		}
  		$foil_rarity_chance = rand(1,$total_chances);
  		$foil_rarity = "C";
  		$foil_land = FALSE;
  		$and_not = "";
  		if($foil_rarity == 121){
  			$foil_rarity = "M";
  		}else if($foil_rarity > 112){
  			$foil_rarity = "R";
  		}else if($foil_rarity > 88){
  			$foil_rarity = "U";
  		}else if($foil_rarity > 80){
  			$foil_land = TRUE;
  		}
  		if($foil_land){
  			$and_not = " AND type NOT LIKE 'Basic%Land%'";
  		}
  		$plc = "";
  		if($set == "PLC"){
  			$plc = " OR cardset='PCT'";
  		}
    	$select = "/*312 " . $sete . "*/ SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE (cardset='" . mysql_real_escape_string($set) . "'" . $plc . ")" . $and_not . " AND rarity='" . $foil_rarity . "' ORDER BY RAND() LIMIT 1";  	
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $foil = $db->sql_fetchrowset_assoc($id);
      array_pop($commons);
      shuffle($commons);
      if(is_array($uncommons)){
        $return = array_merge($commons, $uncommons);
      }
      if(is_array($rare)){
      	$return = array_merge($return, $rare);
      }
      $return = array_merge($return, $foil);
    }else{
      shuffle($commons);
      $return = $commons;
      if(is_array($uncommons)){
        $return = array_merge($return, $uncommons);
      }
      if(is_array($rare)){
      	$return = array_merge($return, $rare);
      }
    }
    //echo "ps=" .  $this->pack_sizes[$this->current_pack] . " r=" . count($return) . " c=" . count($commons) . " u=" . count($uncommons) . " r=" . count($rare) . " l=" . count($lands) . " f=" . count($foil) . "<br />\n";
  	$return = array_combine(range(1, $this->pack_sizes[$this->current_pack]), array_values($return));
  	
  	// Go through each card and tack on its position in the pack and whether or not it is foil
  	
  	for($i=1;$i<= $this->pack_sizes[$this->current_pack];$i++){
  		$return[$i]['position'] = $i;
  		if($set_details['foil'] == 1 && $foil_chance == 5 && $i == $this->pack_sizes[$this->current_pack] ){
  		  $return[$i]['foil'] = 1;
  		}else{
  		  $return[$i]['foil'] = 0;
  	  }
  	}
  	return $return;
  }
  
}

?>