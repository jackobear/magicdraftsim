<?php

// Represents an MTG sealed deck card pool

include('config_db.php');
include('magicTournament.class.php');

class sealedDeck extends magicTournament{

  private $cardcount;
  private $sealed_id;
  private $basic_lands;        // The five basic lands that can be added to the draft pool for deckbuilding

  // Default constructor
  
  public function __construct($set1, $set2, $set3, $set4, $set5, $set6, $sealed_id=""){
  	global $db;
  	$this->timestamp = time();
  	$this->session = session_id();
  	$this->sets[1] = $set1;
  	$this->sets[2] = $set2;
  	$this->sets[3] = $set3;
  	$this->sets[4] = $set4;
  	$this->sets[5] = $set5;
  	$this->sets[6] = $set6;
  	$this->creature_count = 0;
  	
  	if($sealed_id != ""){
  		
  		// Retrieve the requested sealed deck
  		
  		$select = "SELECT timestamp, cards, added_lands FROM sealed_decks WHERE id='" . mysql_real_escape_string($sealed_id) . "'";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        die();
      }else{
        $id = $db->query_result;  
        $saved_deck = $db->sql_fetchrow_assoc($id);
        $this->cards = unserialize($saved_deck['cards']);
        $this->added_lands = unserialize($saved_deck['added_lands']);
        $this->timestamp = $saved_deck['timestamp'];
      }
  	}else if($set1 == "CUB" || $set2 == "CUB" || $set3 == "CUB" || $set4 == "CUB" || $set5 == "CUB" || $set6 == "CUB"){
  		
  		// Cube sealed deck is special...don't want any duplicates
  		
  		$pack_count = 0;
  		if($set1 == "CUB") $pack_count++;
  		if($set2 == "CUB") $pack_count++;
  		if($set3 == "CUB") $pack_count++;
  		if($set4 == "CUB") $pack_count++;
  		if($set5 == "CUB") $pack_count++;
  		if($set6 == "CUB") $pack_count++;
  		$select = "SELECT * FROM cards WHERE cardset='CUB' ORDER BY RAND() LIMIT " . $pack_count * 15;
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        die();
      }else{
        $id = $db->query_result;  
        $this->cards = $db->sql_fetchrowset_assoc($id);
      }
  		
      for($i=0;$i<count($this->cards);$i++){
      	if(strpos($this->cards[$i]['type'], "Creature") !== FALSE){
      		$this->creature_count++;
      	}
      	$this->cards[$i]['cmc'] = $this->spellCMC($this->cards[$i]['cost']);
      	$this->cards[$i]['id'] = $i;
      	$this->cards[$i]['ppp'] = $i;
      }
  		
  	}else{
  	
    	// Crack all the packs and set creature count and converted mana costs for each card
  
      $this->current_pack = 1;
      $this->cards = $this->crackPack($set1);
      $this->current_pack++;
      $this->cards = array_merge($this->cards, $this->crackPack($set2));
      $this->current_pack++;
      $this->cards = array_merge($this->cards, $this->crackPack($set3));
      $this->current_pack++;
      $this->cards = array_merge($this->cards, $this->crackPack($set4));
      if($set5 != ""){
        $this->current_pack++;
        $this->cards = array_merge($this->cards, $this->crackPack($set5));
      }
      if($set6 != ""){
        $this->current_pack++;
        $this->cards = array_merge($this->cards, $this->crackPack($set6));
      }
      for($i=0;$i<count($this->cards);$i++){
      	if(strpos($this->cards[$i]['type'], "Creature") !== FALSE){
      		$this->creature_count++;
      	}
      	$this->cards[$i]['cmc'] = $this->spellCMC($this->cards[$i]['cost']);
      	$this->cards[$i]['id'] = $i;
      	$this->cards[$i]['ppp'] = $i;
      }
    }
  }
  
  // Open a new sealed deck unless one is already in the session
  
  public static function openSealedDeck($set1, $set2, $set3, $set4, $set5, $set6, $sealed_id=""){
    session_start();
    if(isset($_SESSION['sealedDeck']) === TRUE){
    	//echo "<br>FETCHED OLD SESSION DATA<br>";
      return unserialize($_SESSION['sealedDeck']);
    }else{
      //echo "<br>NO SESSION DATA FOUND, STARTING ANEW<br>";
    }
    return new sealedDeck($set1, $set2, $set3, $set4, $set5, $set6, $sealed_id);  
  }
  
  // Save object to session variable
  
  public function __destruct(){
    $_SESSION['sealedDeck'] = serialize($this);
  }
  
  // Returns an array of the 5 basic lands from the proper set that can be used to build a draft deck
  
  public function getBasicLand(){
  	global $db;
  	$select = "SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick FROM cards WHERE type LIKE 'Basic Land%' AND cardset='" . mysql_real_escape_string($this->sets[1]) . "' ORDER BY `name` ASC LIMIT 5";
    if ( !($result = $db->sql_query($select)) ) {					
      echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      die();
    }
    $id = $db->query_result;  
    $lands = $db->sql_fetchrowset_assoc($id);
  	if($lands[0]['id'] == ""){
  		$select = "SELECT `order` FROM cardsets WHERE code='" . mysql_real_escape_string($this->sets[1]) . "'";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        die();
      }
      $id = $db->query_result;  
      $order = $db->sql_fetchrowset_assoc($id);
      if($order[0]['order'] == "" || $order[0]['order'] == 0){
      	$select = "SELECT `order` FROM cardsets ORDER BY `order` DESC LIMIT 1";
        if ( !($result = $db->sql_query($select)) ) {					
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
          die();
        }
        $id = $db->query_result;  
        $order = $db->sql_fetchrowset_assoc($id);
      }
      $select = "SELECT code FROM cardsets WHERE `order`<" . $order[0]['order'] . " AND `order` >=";
      $select .= ($order[0]['order'] - 5) . " ORDER BY `order` DESC";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        die();
      }
      $id = $db->query_result;  
      $mother_sets = $db->sql_fetchrowset_assoc($id);
    	$select = "SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick FROM cards WHERE type LIKE '%Basic%Land%' AND cardset='" . mysql_real_escape_string($mother_sets[0]['code']) . "' ORDER BY `name` ASC LIMIT 5";
      if ( !($result = $db->sql_query($select)) ) {					
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
      }
      $id = $db->query_result;  
      $lands = $db->sql_fetchrowset_assoc($id);
  	}
  	$this->basic_lands = $lands;
  	return $lands;
  }
  
  // Move a card from back or to the main deck as opposed to the sideboard
  // Receives the card's PPP (Pack, Player, Card-Position) in the draft as an ID
  // Returns true on success, false on failure...triggered by AJAX requests
  
  public function moveCard($ppp, $destination){
  	$matches = array();
  	preg_match("#(\d+)#", $ppp, &$matches);
  	if(count($matches) != 2){
  		$new_matches = array();
  		preg_match("#Lnd(\d+)#", $ppp, &$new_matches);
  		if(count($new_matches) != 2){
  		  return false;
  		}else{
  		  // Looks like an added basic land
    	  if($destination == "main"){
    	    $this->added_lands[$new_matches[1]]['main_deck'] = 1;
    	    return true;
    	  }else if($destination == "side"){
    	    $this->added_lands[$new_matches[1]]['main_deck'] = 0;
    	    return true;
    	  }else{
    	    return false;
    	  } 		  
  	  }
  	}else{
  	  if($destination == "main"){
  	    $this->cards[$matches[1]]['main_deck'] = 1;
  	    return true;
  	  }else if($destination == "side"){
  	    $this->cards[$matches[1]]['main_deck'] = 0;
  	    return true;
  	  }else{
  	    return false;
  	  }
    }
  }
  
  // Adds land to the sealed pool
  
  public function addLand($land_index, $num_forests, $num_islands, $num_mountains, $num_plains, $num_swamps){
  	$num_lands = array($num_forests, $num_islands, $num_mountains, $num_plains, $num_swamps);
  	for($i=0;$i<count($num_lands);$i++){
  		for($x=0;$x<$num_lands[$i];$x++){
  			$index = count($this->added_lands);
  			$this->added_lands[$index] = $this->basic_lands[$i];
  			$this->added_lands[$index]['ppp'] = "Lnd" . $land_index;
  			$this->added_lands[$index]['cmc'] = 0;
  			$this->added_lands[$index]['main_deck'] = 1;
  			$land_index++;
  		}
  	}
  }
  	
	// Save this build to the database
	
	public function save(){
		global $db;
  	$insert = "INSERT INTO sealed_decks (timestamp, cards, added_lands) VALUES('" . date("Y-m-j H:i:s") . "', '";
  	$insert .= mysql_real_escape_string(serialize($this->cards)) . "', '";
  	$insert .= mysql_real_escape_string(serialize($this->added_lands)) . "')";
    if ( !($result = $db->sql_query($insert)) ) {					
      echo "Couldn't insert row because " . @mysql_error($db->db_connect_id) . "<br>\n";
      die();
    }
    $this->sealed_id = $db->sql_nextid();
    return $this->sealed_id;
	} // save
	
	// Return the added lands for this sealed deck
	
	public function getAddedLands(){
		return $this->added_lands;
	}
  
  // Export this decklist to popular formats  
  
  public function export($format){
   	$return = "// Generated by MagicDraftSim.com on " . date("M jS, Y g:iA T", $this->timestamp) . "\r";
   	if($format == 'Apprentice'){
   		$return .= "// Magic Apprentice decklist\r\r";
   	}else if($format == 'MWS'){
   		$return .= "// Magic Workshop decklist\r\r";  		
   	}else if($format == 'html'){
   		$return .= "\r";  		
   	}else{
   	  return 'Format not supported';
    }
   	$main = array();
   	$side = array();
    for($i=0;$i<count($this->cards);$i++){
			$incremented = FALSE;
			if($this->cards[$i]['main_deck'] == 1){
        for($x=0;$x<count($main);$x++){
       	  if( $this->cards[$i]['name'] == $main[$x]['name'] && $this->cards[$i]['cardset'] == $main[$x]['cardset']){
             $main[$x]['quantity'] += 1;
             $incremented = TRUE;
           }
       	}
       	if(!$incremented){
       		$index = count($main);
     		  $main[$index]['name'] = $this->cards[$i]['name'];
     		  $main[$index]['cardset'] = $this->cards[$i]['cardset'];
     		  $main[$index]['quantity'] = 1;
     		}
     	}else{
        for($x=0;$x<count($side);$x++){
       	  if( $this->cards[$i]['name'] == $side[$x]['name'] && $this->cards[$i]['cardset'] == $side[$x]['cardset']){
             $side[$x]['quantity'] += 1;
             $incremented = TRUE;
           }
       	}
       	if(!$incremented){
       		$index = count($side);
     		  $side[$index]['name'] = $this->cards[$i]['name'];
     		  $side[$index]['cardset'] = $this->cards[$i]['cardset'];
     		  $side[$index]['quantity'] = 1;
     		}
      }
  	}
  	
  	// Deal w/ added lands
  	
  	for($i=0;$i<count($this->added_lands);$i++){
  		$incremented = FALSE;
  		if($this->added_lands[$i]['main_deck'] == 1){
  			for($x=0;$x<count($main);$x++){
  				if($this->added_lands[$i]['name'] == $main[$x]['name'] && 
  				   $this->added_lands[$i]['cardset'] == $main[$x]['cardset']){
  				  $main[$x]['quantity'] += 1;
  				  $incremented = TRUE;
  				}
  			}
				if(!$incremented){
					$index = count($main);
					$main[$index]['name'] = $this->added_lands[$i]['name'];
					$main[$index]['cardset'] = $this->added_lands[$i]['cardset'];
					$main[$index]['main_deck'] = $this->added_lands[$i]['main_deck'];
					$main[$index]['quantity'] = 1;
				}
  		}else{
  			for($x=0;$x<count($side);$x++){
  				if($this->added_lands[$i]['name'] == $side[$x]['name'] && 
  				   $this->added_lands[$i]['cardset'] == $side[$x]['cardset']){
  				  $side[$x]['quantity'] += 1;
  				  $incremented = TRUE;
  				}
  			}
				if(!$incremented){
					$index = count($side);
					$side[$index]['name'] = $this->added_lands[$i]['name'];
					$side[$index]['cardset'] = $this->added_lands[$i]['cardset'];
					$side[$index]['main_deck'] = $this->added_lands[$i]['main_deck'];
					$side[$index]['quantity'] = 1;
				}
  	  }
  	}

    // Callback function for sorting cards by cardname
  
    function cardname_compare($a, $b){
    	return strcasecmp($a['name'], $b['name']);
    }

  	usort($main, "cardname_compare");
  	usort($side, "cardname_compare");
  	for($i=0;$i<count($main);$i++){
  	  if($format == 'Apprentice'){
  	  	$return .= $main[$i]['quantity'] . " " . $main[$i]['name'] . "\r";
    	}else if($format == 'MWS'){
    		$return .= $main[$i]['quantity'] . " [" . $main[$i]['mainet'] . "] " . $main[$i]['name'] . "\r";
    	}else if($format == 'html'){
    		$return .= $main[$i]['quantity'] . " [" . $main[$i]['cardset'] . "] " . $main[$i]['name'] . "\r";
    	}
  	}
  	for($i=0;$i<count($side);$i++){
  	  if($format == 'Apprentice'){
  	  	$return .= "SB: " . $side[$i]['quantity'] . " " . $side[$i]['name'] . "\r";
    	}else if($format == 'MWS'){
    		$return .= "SB: " . $side[$i]['quantity'] . " [" . $side[$i]['cardset'] . "] " . $side[$i]['name'] . "\r";
    	}else if($format == 'html'){
    		$return .= "SB: " . $side[$i]['quantity'] . " [" . $side[$i]['cardset'] . "] " . $side[$i]['name'] . "\r";
    	}
  	}
  	return $return;
  }

}

?>