<?php

// Represents an MTG draft...assumes the perspective of player 1

require_once('config_db.php');
include('magicTournament.class.php');

class draft extends magicTournament{

  private $debug = false;       // Turns on AI feedback for player2

  // 3D array of who picked what cards...pack/player/card-position/player-who-picked (0 for unpicked)
  private $picks;              // See the line above
  private $total_picks;        // Total number of picks taken thus far
  private $current_pick;       // The current pick the players are on
  private $ai_creature_counts; // An array of the bots' creature counts
  private $ai_mana_curves;     // An array of the bots' average mana curve
  private $ai_colors;          // An array of the bots' power rating of the colors of the cards they've picked
  private $ai;                 // A 2d array of modifiers for each bot's personality
  private $basic_lands;        // The five basic lands that can be added to the draft pool for deckbuilding
  private $add_lands;          // Lands that were actually added
  private $ready_to_pick;      // Whether or not the draft is ready to have a card picked from it

  // Default constructor
  // Start a draft with either an array of sets, or an id of a previously played draft

  public function __construct($sets, $draft_id=""){
  	global $db;
  	$this->timestamp = time();
  	$this->session = session_id();
  	$this->draft_id = "";
  	$this->current_pick = 1;
  	$this->current_pack = 1;
  	$this->total_picks = 0;
  	$this->creature_count = 0;
  	$this->ai_creature_counts = array();
  	$this->ai_mana_curves = array();
  	$this->ai_colors = array();
  	$this->basic_lands = array();
  	$this->add_lands = array();
  	$this->ready_to_pick = FALSE;

  	if($draft_id != ""){

  		// Retrieve the requested draft

  		$select = "SELECT sets, pack_sizes, cards FROM drafts WHERE id=" . mysql_real_escape_string($draft_id);
      if ( !($result = $db->sql_query($select)) ) {
        echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
        die();
      }
      $id = $db->query_result;
      $drafts = $db->sql_fetchrow_assoc($id);
  		if($drafts['sets'] != ""){
  			$this->sets = unserialize($drafts['sets']);
  		}
  		if($drafts['pack_sizes'] != ""){
  			$this->pack_sizes = unserialize($drafts['pack_sizes']);
  		}
  		if($drafts['cards'] != ""){

  			// Cards are stored in the db as a 3D array of card IDs...so we'll need to make a huge sql
  			// statement to grab all the details of those cards

  			$card_ids = unserialize($drafts['cards']);
  			$select = "SELECT id, name, rarity, cardset, url, color, cost, pt, type, text, avg_pick, picks2, views FROM cards WHERE ";
  			for($i=1;$i<=count($card_ids);$i++){
  			  for($j=1;$j<=count($card_ids[$i]);$j++){
  			    for($k=1;$k<=count($card_ids[$i][$j]);$k++){
  			    	$select .= "id=" . $card_ids[$i][$j][$k] . " OR ";
  				  }
  				}
  			}
  		  $select = substr($select, 0, strlen($select) - 4);
        if ( !($result = $db->sql_query($select)) ) {
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
          die();
        }
        $id = $db->query_result;
        $cards = $db->sql_fetchrowset_assoc($id);
        $cards_by_id = array();
        for($x=0;$x<count($cards);$x++){
        	$cards_by_id[$cards[$x]['id']] = $x;
        }
        $foil_next = FALSE;
        $foil_set = FALSE;
  			for($i=1;$i<=count($card_ids);$i++){
  				$select = "SELECT foil FROM cardsets WHERE code='" . $this->sets[$i] . "'";
          if ( !($result = $db->sql_query($select)) ) {
            echo "Error finding foil status.<br>\n";
            die();
          }
          $id = $db->query_result;
          $cardset = $db->sql_fetchrow_assoc($id);
          if($cardset['foil'] == 1){
          	$foil_set = TRUE;
          }else{
           $foil_set = FALSE;
          }
  			  for($j=1;$j<=count($card_ids[$i]);$j++){
  			    for($k=1;$k<=count($card_ids[$i][$j]);$k++){
  			    	$this->cards[$i][$j][$k] = $cards[$cards_by_id[$card_ids[$i][$j][$k]]];
  			      $this->cards[$i][$j][$k]['position'] = $k;
  			      if($k+1 == count($card_ids[$i][$j]) && ($this->cards[$i][$j][$k]['rarity'] == "M" || $this->cards[$i][$j][$k]['rarity'] == "R" )){
  			      	$foil_next = TRUE;
  			      }
  			      if($k == count($card_ids[$i][$j]) && $foil_next && $foil_set){
  			      	$this->cards[$i][$j][$k]['foil'] = 1;
  			      	$foil_next = FALSE;
  			      }else{
  			      	$this->cards[$i][$j][$k]['foil'] = 0;
  			      }
  			    }
  			  }
  			}
  			$this->draft_id = $draft_id;
  	  }
    }else{

      // Brand new draft...crack packs and set pack sizes

  	  for($i=0;$i<count($sets);$i++){
  	    $this->sets[$i + 1] = $sets[$i];

  	    // Need to validate these sets here


  	  }

    	// Set the first 8 packs for the first round (pass to the left), initialize AI

    	for($i=1;$i<9;$i++){
    		$cache = true;
    		if($this->debug) $cache = false;
    		$this->cards[1][$i] = $this->crackPack($sets[0], $cache, $i);
    	}

    	// Pack size needs to be set for the AI to understand whats going on
    	// can't we just count the cards in the pack rather than bothering the database?
    	// ...no, packs 2 & 3 havent been cracked...however we could cache pack sizes for each set

    	if($sets[2] == $sets[1]){
    		$this->pack_sizes[2] = $this->pack_sizes[1];
    	}else if($sets[2] != ""){
    	  $select = "SELECT commons, uncommons, rares, lands FROM cardsets WHERE code='" . $sets[2] . "' LIMIT 1";
        if ( !($result = $db->sql_query($select)) ) {
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
          die();
        }
        $id = $db->query_result;
        $set_details = $db->sql_fetchrow_assoc($id);
    	  $this->pack_sizes[2] = $set_details['commons'] + $set_details['uncommons'] + $set_details['rares'] + $set_details['lands'];
    	}else{
    	  $this->pack_sizes[2] = 15;
    	}
    	if($sets[3] == $sets[1]){
    		$this->pack_sizes[3] = $this->pack_sizes[1];
    	}else if($sets[3] == $sets[2]){
    		$this->pack_sizes[3] = $this->pack_sizes[2];
    	}else if($sets[3] != ""){
    	  $select = "SELECT commons, uncommons, rares, lands FROM cardsets WHERE code='" . $sets[3] . "' LIMIT 1";
        if ( !($result = $db->sql_query($select)) ) {
          echo "Couldn't select row because " . @mysql_error($db->db_connect_id) . " SELECT=" . $select . "<br>\n";
          die();
        }
        $id = $db->query_result;
        $set_details = $db->sql_fetchrow_assoc($id);
    	  $this->pack_sizes[3] = $set_details['commons'] + $set_details['uncommons'] + $set_details['rares'] + $set_details['lands'];
    	}else{
    	  $this->pack_sizes[3] = 15;
      }

      // Set pack sizes for any packs past the 3rd


    }

    // Initialize picks and AI

    for($i=1;$i<9;$i++){
   		for($x=1;$x<=$this->pack_sizes[1];$x++){
   			$this->picks[1][$i][$x] = 0;  // Initialize who picked these cards to nobody(0)
   		}
   		if($i > 1){
   		  $this->ai_creature_counts[$i] = 0;
   		  $this->ai_mana_curves[$i] = 0;
   		  $this->ai_colors[$i]['B'] = 0;
   		  $this->ai_colors[$i]['G'] = 0;
   		  $this->ai_colors[$i]['R'] = 0;
   		  $this->ai_colors[$i]['U'] = 0;
   		  $this->ai_colors[$i]['W'] = 0;

   		  // Load Bot config

   		  $random_config = mt_rand(1,8);
   		  if($this->debug && $i == 2) $random_config = 1;
   		  include("ai/" . $random_config . ".php");

   		  // Add noise

   		  $noises = array();
        for($q=0;$q<16;$q++){
        	$noises[$q] = mt_rand(0, 100) * $ai_config['noise_multiplier'] / 100;
        	$plus_or_minus = mt_rand(0,1);
        	if($plus_or_minus == 1){
        		$noises[$q] = -1 * $noises[$q];
        	}
        }
        $ai_config['low_creature_threshold'] += $noises[0] * $ai_config['low_creature_threshold'];
        $ai_config['low_creature_proportion'] += $noises[1] * $ai_config['low_creature_proportion'];
        $ai_config['low_creature_modifier'] += $noises[2] * $ai_config['low_creature_modifier'];
        $ai_config['color_threshold'] += $noises[3] * $ai_config['color_threshold'];
        $ai_config['color_modifier'] += $noises[4] * $ai_config['color_modifier'];
        $ai_config['color_modifier_min'] += $noises[5] * $ai_config['color_modifier_min'];
        $ai_config['splashable_modifier'] += $noises[6] * $ai_config['splashable_modifier'];
        $ai_config['high_mana_curve_avg'] += $noises[7] * $ai_config['high_mana_curve_avg'];
        $ai_config['high_mana_curve_modifier'] += $noises[8] * $ai_config['high_mana_curve_modifier'];
        $ai_config['high_mana_curve_threshold'] += $noises[9] * $ai_config['high_mana_curve_threshold'];
        $ai_config['colorless_threshold'] += $noises[10] * $ai_config['colorless_threshold'];
        $ai_config['colorless_modifier'] += $noises[11] * $ai_config['colorless_modifier'];
        $ai_config['color_weight'] += $noises[12] * $ai_config['color_weight'];
        $ai_config['uncommon_modifier'] += $noises[13] * $ai_config['uncommon_modifier'];
        $ai_config['rare_modifier'] += $noises[14] * $ai_config['rare_modifier'];
        $ai_config['mythic_modifier'] += $noises[15] * $ai_config['mythic_modifier'];

        // Load bot personality

   		  $this->ai[$i] = $ai_config;
   		}
   	}
  }

  // Start a new draft

  public static function startDraft($sets, $draft_id=""){
    session_start();
    if(isset($_SESSION['draft']) === TRUE){
    	//echo "<br>FETCHED OLD SESSION DATA<br>";
      return unserialize($_SESSION['draft']);
    }else{
      //echo "<br>NO SESSION DATA FOUND, STARTING ANEW<br>";
    }
    return new draft($sets, $draft_id);
  }

  // Save object to session variable

  public function __destruct(){
    $_SESSION['draft'] = serialize($this);
  }

  // Permanently save this draft to the database...Returns the draft id
  // Serializing is a pretty inefficient use of database space...optimize this and the constructor

  public function saveDraft(){
  	global $db;

  	// If this draft already has an ID, its already in the database

  	if($this->draft_id == ""){
    	$card_ids = array();
    	for($pack=1;$pack<=count($this->cards);$pack++){
    	  for($player=1;$player<=count($this->cards[$pack]);$player++){
    	    for($position=1;$position<=count($this->cards[$pack][$player]);$position++){
    	    	$card_ids[$pack][$player][$position] = $this->cards[$pack][$player][$position]['id'];
    	    }
    	  }
    	}
    	$insert = "INSERT INTO drafts (timestamp, sets, pack_sizes, cards, picks) VALUES(" . time() . ", '";
    	$insert .= serialize($this->sets) . "', '" . serialize($this->pack_sizes) . "', '" . serialize($card_ids);
    	$insert .= "', '" . serialize($this->picks) . "')";
      if ( !($result = $db->sql_query($insert)) ) {
        echo "Couldn't insert row because " . @mysql_error($db->db_connect_id) . "<br>\n";
        die();
      }
      $this->draft_id = $db->sql_nextid();
    }
    return $this->draft_id;
  }

  // Get an array of cards which the player can pick from

  public function getCards(){

  	$pack_index = 0;
  	if($this->current_pack == 2){
  		$pack_index = $this->current_pick % 8;
  		if($pack_index == 0) $pack_index = 8;
  	}else{
  	  $pack_index = 2 - ($this->current_pick % 8);
  	  if($pack_index < 1) $pack_index += 8;
  	}
  	$return = array();
  	for($i=1;$i<=$this->pack_sizes[$this->current_pack];$i++){
  		if($this->picks[$this->current_pack][$pack_index][$i] == 0){
  			$return[count($return)] = $this->cards[$this->current_pack][$pack_index][$i];
  		}
  	}
  	if($this->current_pack <= count($this->sets) && $this->current_pick <= $this->pack_sizes[$this->current_pack]){
  		$this->ready_to_pick = TRUE;
  	}
  	if($this->debug && 0){
  		echo "pack_index=" . $pack_index . " curpick=" . $this->current_pick . " currpacksize=" . $this->pack_sizes[$this->current_pack];
  		echo " currpack=" . $this->current_pack . "<br>";
  		echo "cards dump:<br><pre>";
  		var_dump($this->cards[$this->current_pack]);
  		echo "</pre>";
  	}
  	return $return;
  }

  // Pick a card...ignore the request if we're not ready to pick

  public function pickCard($position){
  	global $db;
  	if($this->ready_to_pick){
    	$this->total_picks++;
    	$pack_index = 0;
    	if($this->current_pack == 2){
    		$pack_index = $this->current_pick % 8;
    		if($pack_index == 0) $pack_index = 8;
    	}else{
    	  $pack_index = 2 - ($this->current_pick % 8);
    	  if($pack_index < 1) $pack_index += 8;
    	}
    	if(strpos($this->cards[$this->current_pack][$pack_index][$position]['type'], "Creature") !== FALSE){
    		$this->creature_count++;
    	}
    	$this->picks[$this->current_pack][$pack_index][$position] = 1;

    	// Update our card ratings database here based on this pick

    	$update = "UPDATE cards SET avg_pick=((avg_pick * picks2) + " . $this->current_pick . ") / (picks2 + 1), ";
    	$update .= "views=views+1, picks2=picks2+1 WHERE id=" . $this->cards[$this->current_pack][$pack_index][$position]['id'];
      //echo "updated picks2=" . $update . "<br><br>";
      if ( !($result = $db->sql_query($update)) ) {
        echo "Couldn't update row because " . @mysql_error($db->db_connect_id) . "1<br>\n";
        die();
      }


      // Increment the view count for each of the cards left in this pack
      // if you want a pick count, just sum them in a seperate call

      $update = "UPDATE cards SET views = views + 1 WHERE ";
      $first_card = true;
      $card_names = "";
      for($i=1;$i<=count($this->picks[$this->current_pack][$pack_index]);$i++){
      	if($this->picks[$this->current_pack][$pack_index][$i] == 0){
      	  if(!$first_card) $update .= " OR ";
      	  $update .= "id='" . $this->cards[$this->current_pack][$pack_index][$i]['id'] . "'";
      	  $card_names .= $this->cards[$this->current_pack][$pack_index][$i]['name'] . ", ";
      	  $first_card = false;
      	}
      }
      if(!$first_card){
        if ( !($result = $db->sql_query($update)) ) {
          echo "Couldn't update row because " . @mysql_error($db->db_connect_id) . "2<br>\n";
          die();
        }
      }
      if($this->debug) echo "updated card views=" . $card_names . " <br><br>u=" . $update . "<br><Br>\n";

      // Let the bots make their picks

    	for($i=2;$i<9;$i++){
    		$this->AIpick($i);
    	}

    	// Increment the pick number and start up a new pack if needbe

    	$this->current_pick++;
    	if($this->current_pick > $this->pack_sizes[$this->current_pack] && $this->current_pack < 3){
    		$this->current_pack++;
    		$this->current_pick = 1;
       	for($i=1;$i<9;$i++){
       		// Make sure we don't already have the next pack loaded
       		if($this->cards[$this->current_pack][$i][1]['id'] == ""){
       			$cache = true;
       			if($this->debug) $cache = false;
       		  $this->cards[$this->current_pack][$i] = $this->crackPack($this->sets[$this->current_pack], $cache, ($i + (8 * ($this->current_pack - 1))));
       		}
       		for($x=1;$x<=$this->pack_sizes[$this->current_pack];$x++){
       			$this->picks[$this->current_pack][$i][$x] = 0;
       		}
       	}
    	}
    	$this->ready_to_pick = FALSE;
    }
  }

  // AI helper function...simulates an unseen player making a pick
  // The pick is based on the average pick rating from the database and modified for colors of cards
  // picked so far, the creature count thus far, and how deep into the draft we are

  private function AIpick($player){

  	global $db;

  	// Config options...see config files in ai directory for more info

  	$low_creature_threshold = $this->ai[$player]['low_creature_threshold'];    // How many picks deep before considering creatures more
  	$low_creature_proportion = $this->ai[$player]['low_creature_proportion'];  // Minimum creature proportion before considering creature more
  	$low_creature_modifier = $this->ai[$player]['low_creature_modifier'];     // Maximum rating modifier (end of pack 3)
  	$color_threshold = $this->ai[$player]['color_threshold'];            // How many picks deep before considering color more
  	$color_modifier = $this->ai[$player]['color_modifier'];            // Maximum additional rating modifier for matching color (top 2 colors)
  	$color_modifier_min = $this->ai[$player]['color_modifier_min'];      // Minimum rating modifier for matching color (top 2 colors)
  	$splashable_modifier = $this->ai[$player]['splashable_modifier'];       // Counteracts color modifier when there is only one off-color mana
  	$high_mana_curve_avg = $this->ai[$player]['high_mana_curve_avg'];        // How high the average mana cost must be before valuing cards under 3
  	$high_mana_curve_modifier = $this->ai[$player]['high_mana_curve_modifier'];  // Maximum rating modifier for lower cost spells
  	$high_mana_curve_threshold = $this->ai[$player]['high_mana_curve_threshold']; // How many picks deep before considering cheaper spells more
  	$target_avg_cmc = $this->ai[$player]['target_avg_cmc'];           // Cards that deviate from this cmc will be adjusted accordingly
  	$target_avg_cmc_modifier = $this->ai[$player]['target_avg_cmc_modifier']; // Multiplied by the cmc difference from the target
  	$creature_modifier = $this->ai[$player]['creature_modifier'];     // Every spell will be adjusted based on whether it is a creature
  	$colorless_threshold = $this->ai[$player]['colorless_threshold'];        // How many picks deep until colorlessness isn't extra valuable
  	$colorless_modifier = $this->ai[$player]['colorless_modifier'];        // Maximum rating modifier for colorless spells in the first few picks
  	$color_weight = $this->ai[$player]['color_weight'];               // Maximum multiplier for weighting a color's value in regards to avg_pick
  	$uncommon_modifier = $this->ai[$player]['uncommon_modifier'];         // Maximum modifier for uncommon spells (not dependent on pick number)
  	$rare_modifier = $this->ai[$player]['rare_modifier'];             // Maximum modifier for rare spells
  	$mythic_modifier = $this->ai[$player]['mythic_modifier'];         // Maximum modifier for mythic spells

  	// First figure out which pack we're talking about

  	if(0 && $this->debug && $player == 2){
  		echo "<font color='white'>";
  		echo "<pre>";
  		var_dump($this->ai[2]);
  		echo "</pre>";
  	}
  	$pack_index = 0;
  	if($this->current_pack == 2){
  		$pack_index = ($this->current_pick + $player - 1) % 8;
  		if($pack_index == 0) $pack_index = 8;
  	}else{
    	$pack_index = ($player + 1) - ($this->current_pick % 8);
    	if($pack_index < 1) $pack_index += 8;
    	if($pack_index > 8) $pack_index -= 8;
  	}

  	// Determine how far into the draft we are and determine percentages for each rating modifier metric

  	$picks_thus_far = 0;
  	$total_picks = 0;
  	for($pack=1;$pack<4;$pack++){
      $total_picks += $this->pack_sizes[$pack];
      if($this->current_pack > $pack){
      	$picks_thus_far += $this->pack_sizes[$pack];
      }
    }
    $picks_thus_far += $this->current_pick;
    if($this->debug && $player == 2){
     echo " current pack=" . $this->current_pack . " packsize=" . $this->pack_sizes[$this->current_pack] . " picks_thus_far=" . $picks_thus_far . "<br /><br />";
    }

    // Safeguard hack...this is coming up as zero in rare circumstances...

    if($total_picks == 0){
    	$total_picks = 45;
    }

    // Percentages are multipliers for certain modifiers based on total picks and picks thus far

    $color_pick_percentage = $picks_thus_far * 4 / $total_picks;
    $pick_percentage = $picks_thus_far / $total_picks;
    if($this->current_pack == 2){
    	if($this->current_pick == 1){
    		$color_pick_percentage *= 0.6;
    	}else if($this->current_pick == 1){
    		$color_pick_percentage *= 0.8;
    	}else if($this->current_pick == 1){
    		$color_pick_percentage *= 0.9;
    	}
    }
    if($this->debug && $player == 2){
  	  echo "Pick#=" . $picks_thus_far . " TotalPicks=" . $total_picks . "<br />";
  	  echo "Color%=" . number_format($color_pick_percentage, 2) . " Pick%=" . number_format($pick_percentage, 2) . "<br /><br />";
    }

    // Determine our main colors

  	$main_color1 = "";
  	$main_color2 = "";
  	$biggest_value1 = 0;
  	$biggest_value2 = 0;
  	foreach ($this->ai_colors[$player] as $key => $value){
  		if($value > $biggest_value1){
  			$temp_value = $biggest_value1;
  			$temp_color = $main_color1;
  			$main_color1 = $key;
  			$biggest_value1 = $value;
  			if($temp_value > $biggest_value2){
  				$main_color2 = $temp_color;
  				$biggest_value2 = $temp_value;
  			}
  		}else if($value > $biggest_value2){
  			$main_color2 = $key;
  			$biggest_value2 = $value;
  		}
  	}
  	if($this->debug && $player == 2){
  		foreach ($this->ai_colors[$player] as $key => $value){
  			echo $key . "=" . number_format($value, 2) . " ";
  		}
  		echo "<br />\n";
  		echo "Main Colors=" . $main_color1 . $main_color2 . "<br /><br />\n";
  	}

  	// Construct an array of pick ratings for the current pack based on current colors and creature count

  	$ratings = array();
  	for($i=1;$i<=$this->pack_sizes[$this->current_pack];$i++){
  		if($this->picks[$this->current_pack][$pack_index][$i] == 0){

  			// We've found a card passed to us...lets see if it's colors match ours
  			// Special colors A=GW D=WU E=W2 F=U2 H=B2 I=RU J=R2 K=BR L=GR M=G2 O=BW P=RW Q=BG S=GU V=BU  /=split card
  			// Phyrexia bullshit !=White, @=Green, #=Blue, $=Black, ^=Red...note that blue is #, not ` as in mws

  			$colors = str_replace("%", "", str_replace("/", "", $this->cards[$this->current_pack][$pack_index][$i]['cost']));
  			$colors = str_replace("E", "W", str_replace("F", "U", str_replace("H", "B", str_replace("J", "R", $colors))));
  			$colors = str_replace("!", "W", str_replace("@", "G", str_replace("#", "U", str_replace("$", "B", $colors))));
  			$colors = str_replace("^", "R", $colors);
  			$colors = preg_replace("#\d+#", "", str_replace("X", "", str_replace("M", "G", $colors)));
  			$splashable = FALSE;
  			$colorless = FALSE;
  			$main_colors_included = 0;
  			if(strlen($colors) == 0){
  				$colorless = TRUE;
  			}
  			$on_color = TRUE;
  			for($x=0;$x<strlen($colors);$x++){
  				if($colors[$x] != $main_color1 && $colors[$x] != $main_color2){
					  $on_color = FALSE;
  					if( ($colors[$x] == "A" && ($main_color1 == "G" || $main_color1 == "W" || $main_color2 == "G" || $main_color2 == "W")) ||
  					    ($colors[$x] == "D" && ($main_color1 == "W" || $main_color1 == "U" || $main_color2 == "W" || $main_color2 == "U")) ||
  					    ($colors[$x] == "I" && ($main_color1 == "R" || $main_color1 == "U" || $main_color2 == "R" || $main_color2 == "U")) ||
  					    ($colors[$x] == "K" && ($main_color1 == "B" || $main_color1 == "R" || $main_color2 == "B" || $main_color2 == "R")) ||
  					    ($colors[$x] == "L" && ($main_color1 == "G" || $main_color1 == "R" || $main_color2 == "G" || $main_color2 == "R")) ||
  					    ($colors[$x] == "O" && ($main_color1 == "B" || $main_color1 == "W" || $main_color2 == "B" || $main_color2 == "W")) ||
  					    ($colors[$x] == "P" && ($main_color1 == "R" || $main_color1 == "W" || $main_color2 == "R" || $main_color2 == "W")) ||
  					    ($colors[$x] == "Q" && ($main_color1 == "B" || $main_color1 == "G" || $main_color2 == "B" || $main_color2 == "G")) ||
  					    ($colors[$x] == "S" && ($main_color1 == "G" || $main_color1 == "U" || $main_color2 == "G" || $main_color2 == "U")) ||
  					    ($colors[$x] == "V" && ($main_color1 == "B" || $main_color1 == "U" || $main_color2 == "B" || $main_color2 == "U"))
  					  ){
  					  $on_color = TRUE;
  					}
  				}else{
  				  $main_colors_included++;
  			  }
  			}
  			if(strlen($colors) - $main_colors_included < 2){
  				$splashable = TRUE;
  			}

  			// Modify the rating according to picks made thus far

  			$die_roll = mt_rand(1,60) / 100;
  			//$rating = 16 - $this->cards[$this->current_pack][$pack_index][$i]['avg_pick'] + $die_roll;
  			$db_picks = $this->cards[$this->current_pack][$pack_index][$i]['picks2'];
  			$db_views = $this->cards[$this->current_pack][$pack_index][$i]['views'];
  			$rating = 0;
  			$scale = 0;
  			if($db_views > 3){
  				$rating = $db_picks / $db_views * 100;
  				$scale = $rating;
  			}else{
  			  $rating = 100 - (log($this->cards[$this->current_pack][$pack_index][$i]['avg_pick'], 15) * 100);
  			  $scale = $rating;
  		  }
  			if($scale < 12.5){
  				$scale = 25 - $scale;  // A pick pct of 0% should use modifiers the same as a card at 25%
  			}
  			$scale_modifier = 2 * log($scale, 100); // Scales any modifiers according to a log scale centered on a pick% of 12.5
  			$rating += ($die_roll * $scale_modifier);
  			if($this->debug && $player == 2){
  			 echo "Picks=" . $db_picks . " Views=" . $db_views . " Die Roll=" . $die_roll . " <b>Rating=" . number_format($rating, 2) . "</b> ScaleMod=" . number_format($scale_modifier, 2) . " ";
  			}
  			if(strpos($this->cards[$this->current_pack][$pack_index][$i]['type'], "Basic Land") !== FALSE ){
  				// basic land penalty
  				$on_color = false;
  				$splashable = false;
  				$colorless = false;
  			}
  			if($picks_thus_far >= $color_threshold && $on_color){
  				$current_color_modifier = $color_modifier_min + ($color_pick_percentage * $color_modifier * $scale_modifier);
  				if($colorless){
  					$current_color_modifier = $current_color_modifier / 2;
  				}
  				$rating += $current_color_modifier;
  				if($this->debug && $player == 2){
  				  echo "ColorMod=+" . number_format($current_color_modifier, 2) . " ";
  				}
  			}else if($picks_thus_far >= $color_threshold && !$on_color && $splashable){
  				$rating += ($color_pick_percentage * $splashable_modifier * $scale_modifier) / 4;
  				if($this->debug && $player == 2){
  				  echo "SplashMod=+" . number_format(($color_pick_percentage * $splashable_modifier * $scale_modifier / 4), 2) . " ";
  				}
  			}else if($picks_thus_far >= $color_threshold && !$on_color){
  				$current_color_modifier = $color_modifier_min + ($color_pick_percentage * $color_modifier * $scale_modifier);
  				$rating += ($current_color_modifier / 2 * -1);
  				if($this->debug && $player == 2){
  				  echo "OffColorMod=" . number_format(($current_color_modifier / 2 * -1), 2) . " ";
  				}
  			}
  			if($picks_thus_far >= $low_creature_threshold &&
  			    strpos($this->cards[$this->current_pack][$pack_index][$i]['type'], "Creature") !== FALSE &&
  			    ($this->ai_creature_counts[$player] / $picks_thus_far < $low_creature_proportion) ){
  				$rating += ($low_creature_modifier * $pick_percentage * $scale_modifier);
  				if($this->debug && $player == 2){
  				  echo "NeedCrittersMod=+" . number_format(($low_creature_modifier * $pick_percentage * $scale_modifier), 2) . " ";
  				}
  			}else if($picks_thus_far >= $low_creature_threshold &&
  			    strpos($this->cards[$this->current_pack][$pack_index][$i]['type'], "Creature") === FALSE &&
  			    ($this->ai_creature_counts[$player] / $picks_thus_far < $low_creature_proportion) ){
  				$rating += ($low_creature_modifier * $pick_percentage * -1 * $scale_modifier);
  				if($this->debug && $player == 2){
  				  echo "NeedCrittersMod=" . number_format(($low_creature_modifier * $pick_percentage * -1 * $scale_modifier), 2) . " ";
  				}
  			}
  			if(strpos($this->cards[$this->current_pack][$pack_index][$i]['type'], "Creature") !== FALSE && $creature_modifier != 0){
  				$rating += ($creature_modifier * $scale_modifier);
  				if($this->debug && $player == 2){
  				  echo "CritterMod=" . number_format(($creature_modifier * $scale_modifier), 2) . " ";
  				}
  			}
  			$current_cmc = $this->spellCMC($this->cards[$this->current_pack][$pack_index][$i]['cost']);
  			if($picks_thus_far >= $high_mana_curve_threshold && $this->ai_mana_curves[$player] > $high_mana_curve_avg &&
  			    $current_cmc < $high_mana_curve_avg ){
  			  $cmc_modifier = ($high_mana_curve_avg - $current_cmc) * 2/3;
  				$rating += ($high_mana_curve_modifier * $cmc_modifier * $pick_percentage * $scale_modifier);
  				if($this->debug && $player == 2){
  				  echo "HiCurveMod=" . number_format(($high_mana_curve_modifier * $cmc_modifier * $pick_percentage * $scale_modifier), 2) . " ";
  				}
  			}
  			$cmc_diff = $target_avg_cmc - $current_cmc;
  			if($cmc_diff < 0) $cmc_diff *= -1;
 				$rating += $target_avg_cmc_modifier * $cmc_diff * -2/3 * $scale_modifier;
 				if($this->debug && $player == 2){
 				  echo "CMCMod=" . number_format(($target_avg_cmc_modifier * $cmc_diff * -2/3 * $scale_modifier), 2) . " ";
 				}
  			if($picks_thus_far <= $colorless_threshold && $colorless){
  				$rating += ($colorless_modifier * $scale_modifier);
  				if($this->debug && $player == 2){
  				  echo "ColorlessMod=+" . number_format(($colorless_modifier * $scale_modifier), 2) . " ";
  				}
  			}
  			if($this->cards[$this->current_pack][$pack_index][$i]['rarity'] == 'U'){
  				$rating += $uncommon_modifier * $scale_modifier;
  				if($this->debug && $player == 2){
  					echo "UMod=+" . number_format($uncommon_modifier * $scale_modifier, 2) . " ";
  				}
  			}
  			if($this->cards[$this->current_pack][$pack_index][$i]['rarity'] == 'R'){
  				$rating += $rare_modifier * $scale_modifier;
  				if($this->debug && $player == 2){
  					echo "RMod=+" . number_format($rare_modifier * $scale_modifier, 2) . " ";
  				}
  			}
  			if($this->cards[$this->current_pack][$pack_index][$i]['rarity'] == 'M'){
  				$rating += $mythic_modifier * $scale_modifier;
  				if($this->debug && $player == 2){
  					echo "MMod=+" . number_format($mythic_modifier * $scale_modifier, 2) . " ";
  				}
  			}
  			if($this->debug && $player == 2){
  				echo "<b>" . $this->cards[$this->current_pack][$pack_index][$i]['name'] . "</b> (" . number_format((16 - $this->cards[$this->current_pack][$pack_index][$i]['avg_pick']), 2);
  				echo ") rated at <b>" . number_format($rating, 2) . "</b><br />\n";
  			}
  			$ratings[count($ratings)]['rating'] = $rating;
  			$ratings[count($ratings) - 1]['index'] = $i;
  			$ratings[count($ratings) - 1]['colors'] = $colors;
  		}
    }

  	// Make the pick

  	$best_card = 0;
  	$best_rating = 0;
  	$colors = "";
  	for($i=0;$i<count($ratings);$i++){
  		if($ratings[$i]['rating'] > $best_rating){
  			$best_rating = $ratings[$i]['rating'];
  			$best_card = $ratings[$i]['index'];
  			$colors = $ratings[$i]['colors'];
  		}
  	}
  	$this->picks[$this->current_pack][$pack_index][$best_card] = $player;
  	if($this->debug && $player == 2){
  		echo "<br />Picked " . $this->cards[$this->current_pack][$pack_index][$best_card]['name'] . "<br />\n";
  	}

  	// Adjust our preferred colors (weighted by rating) and update our creature count if needbe

  	for($i=0;$i<strlen($colors);$i++){
  		if($colors[$i] == "B" || $colors[$i] == "G" || $colors[$i] == "R" || $colors[$i] == "U" || $colors == "W"){
  			if($this->cards[$this->current_pack][$pack_index][$best_card]['views'] > 0){
  		    $this->ai_colors[$player][$colors[$i]] += $color_weight * ($this->cards[$this->current_pack][$pack_index][$best_card]['picks2'] / $this->cards[$this->current_pack][$pack_index][$best_card]['views']);
        }else{
          $this->ai_colors[$player][$colors[$i]] += $color_weight * 0.125;
        }
  		}
  	}
  	if(strpos($this->cards[$this->current_pack][$pack_index][$best_card]['type'], "Creature") !== FALSE){
  		$this->ai_creature_counts[$player] += 1;
  	}
  	if($this->debug && $player == 2){
  	  echo "</font>";
  	}

  }

  // Returns a 2D array of the given player's picks thus far...doesnt support tertiary sorting...
  // ie...you'll get up to 6 stacks sorted by the main sorting method, then by another method, but not by
  // the last...so you could have 2 cards w/ different names that aren't next to each other

  public function getPicks($player_number, $sort='none'){

  	// This should not be so complex...picks should be saved from last pick



  	$picks = array();
  	for($pack=1;$pack<=count($this->sets);$pack++){
  		for($player=1;$player<9;$player++){
  			for($position=1;$position<=$this->pack_sizes[$pack];$position++){
  				if($this->picks[$pack][$player][$position] == $player_number){
  					$pick_index = count($picks);
  					$picks[$pick_index] = $this->cards[$pack][$player][$position];
  					$picks[$pick_index]['ppp'] = $pack . "x" . $player . "x" . $position;  // Acts as an ID for the card
  					$picks[$pick_index]['cmc'] = $this->spellCMC($picks[$pick_index]['cost']);
  				}
  			}
  		}
  	}
  	if($sort == 'none'){
  	  return $picks;
  	}

  	// Sort the cards into up to 6 stacks of cards according to the given sort type

  	$sorted_picks = array();
  	switch($sort){
  		case "rarity":
  		  for($i=0;$i<count($picks);$i++){
  		  	switch($picks[$i]['rarity']){
  		  		case "M":
  		  		  $sorted_picks[0][count($sorted_picks[0])] = $picks[$i];
  		  		  break;
  		  		case "R":
  		  		  $sorted_picks[1][count($sorted_picks[1])] = $picks[$i];
  		  		  break;
  		  		case "U":
  		  		  $sorted_picks[2][count($sorted_picks[2])] = $picks[$i];
  		  		  break;
  		  		case "C":
  		  		  $sorted_picks[3][count($sorted_picks[3])] = $picks[$i];
  		  		  break;
  		  		default:
  		  		  $sorted_picks[4][count($sorted_picks[4])] = $picks[$i];
  		  		  break;
  		  	}
  		  }
    		for($i=0;$i<count($sorted_picks);$i++){
  		    if(is_array($sorted_picks[$i])){
  	        usort($sorted_picks[$i], array($this, "sortByColor"));
  	      }
  	    }
  		  break;
  		case "cost":
  		  for($i=0;$i<count($picks);$i++){
    		  $cmc = $this->spellCMC($picks[$i]['cost']);
    		  if($cmc < 2){
    		  	$sorted_picks[0][count($sorted_picks[0])] = $picks[$i];
    		  }else if($cmc > 1 && $cmc < 6){
    		  	$sorted_picks[$cmc - 1][count($sorted_picks[$cmc - 1])] = $picks[$i];
    		  }else{
    		    $sorted_picks[5][count($sorted_picks[5])] = $picks[$i];
    		  }
    		}
    		for($i=0;$i<count($sorted_picks);$i++){
  		    if(is_array($sorted_picks[$i])){
  	        usort($sorted_picks[$i], array($this, "sortByColor"));
  	      }
  	    }
  		  break;
  		case "type":
  		  for($i=0;$i<count($picks);$i++){
    		  if(strpos($picks[$i]['type'], "Creature") !== FALSE){
    		  	$sorted_picks[0][count($sorted_picks[0])] = $picks[$i];
    		  }else if(strpos($picks[$i]['type'], "Sorcery") !== FALSE){
    		  	$sorted_picks[1][count($sorted_picks[1])] = $picks[$i];
    		  }else if(strpos($picks[$i]['type'], "Enchantment") !== FALSE){
    		  	$sorted_picks[2][count($sorted_picks[2])] = $picks[$i];
    		  }else if(strpos($picks[$i]['type'], "Instant") !== FALSE){
    		  	$sorted_picks[3][count($sorted_picks[3])] = $picks[$i];
    		  }else if(strpos($picks[$i]['type'], "Artifact") !== FALSE){
    		  	$sorted_picks[4][count($sorted_picks[4])] = $picks[$i];
    		  }else{
    		  	$sorted_picks[5][count($sorted_picks[5])] = $picks[$i];
    		  }
    		}
    		for($i=0;$i<count($sorted_picks);$i++){
  		    if(is_array($sorted_picks[$i])){
  	        usort($sorted_picks[$i], array($this, "sortByColor"));
  	      }
  	    }
  		  break;
  		case "color":
  		default:
  		  for($i=0;$i<count($picks);$i++){
    		  switch($picks[$i]['color']){
    		  	case "B":
    		  	  $sorted_picks[0][count($sorted_picks[0])] = $picks[$i];
    		  	  break;
    		  	case "G":
    		  	  $sorted_picks[1][count($sorted_picks[1])] = $picks[$i];
    		  	  break;
    		  	case "R":
    		  	  $sorted_picks[2][count($sorted_picks[2])] = $picks[$i];
    		  	  break;
    		  	case "U":
    		  	  $sorted_picks[3][count($sorted_picks[3])] = $picks[$i];
    		  	  break;
    		  	case "W":
    		  	  $sorted_picks[4][count($sorted_picks[4])] = $picks[$i];
    		  	  break;
    		  	default:
    		  	  $sorted_picks[5][count($sorted_picks[5])] = $picks[$i];
    		  	  break;
    		  }
    		}
    		for($i=0;$i<6;$i++){
  		    if(is_array($sorted_picks[$i])){
  	        usort($sorted_picks[$i], array($this, "sortByCMC"));
  	      }
  	    }
  		  break;
  	}
  	return $sorted_picks;
  }

  // Move a card from back or to the main deck as opposed to the sideboard
  // Receives the card's PPP (Pack, Player, Card-Position) in the draft as an ID
  // Returns true on success, false on failure...triggered by AJAX requests

  public function moveCard($ppp, $destination){
  	$matches = array();
  	preg_match("#(\d+)x(\d+)x(\d+)#", $ppp, &$matches);
  	if(count($matches) != 4){
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
  	    $this->cards[$matches[1]][$matches[2]][$matches[3]]['main_deck'] = 1;
  	    return true;
  	  }else if($destination == "side"){
  	    $this->cards[$matches[1]][$matches[2]][$matches[3]]['main_deck'] = 0;
  	    return true;
  	  }else{
  	    return false;
  	  }
    }
  }

  // Adds land to the draft pool

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

  // Debugging feedback function

  public function getStatus(){
  	$pack_index = 0;
  	if($this->current_pack == 2){
  		$pack_index = $this->current_pick % 8;
  		if($pack_index == 0) $pack_index = 8;
  	}else{
    	$pack_index = 2 - ($this->current_pick % 8);
    	if($pack_index < 1) $pack_index += 8;
  	}

  	return "pick=" . $this->current_pick . " pack=" . $this->current_pack . " this pack originally opened by player #" . $pack_index;
  }

  // Generic accessors

  public function getPickNumber(){
  	return $this->current_pick;
  }

  public function getPackNumber(){
  	return $this->current_pack;
  }

  public function getAllPicks(){
  	return $this->picks;
  }

  public function getCurrentPackSize(){
  	return $this->pack_sizes[$this->current_pack];
  }

  public function getTotalPicks(){
  	return $this->total_picks;
  }

  public function getCardset($pack){
  	return $this->sets[$pack];
  }

  public function getBotName($player){
  	return $this->ai[$player]['bot_name'];
  }

	// Helper sorting functions

  private function sortByName($a, $b){
  	return strcasecmp($a['name'], $b['name']);
  }
  private function sortByCMC($a, $b){
  	if($a['cmc'] < $b['cmc']){
  		return 1;
  	}else if($a['cmc'] > $b['cmc']){
  		return -1;
  	}else{
  	  $nums = array("1", "2", "3", "4", "5", "6", "7", "8", "9", "0");
  	  $solid_mana_a = str_replace($nums, "", str_replace("/", "", str_replace("X", "", str_replace("%", "", $a['cost']))));
  	  $solid_mana_b = str_replace($nums, "", str_replace("/", "", str_replace("X", "", str_replace("%", "", $b['cost']))));
      if(strlen($solid_mana_a) < strlen($solid_mana_b)){
      	return 1;
      }else if(strlen($solid_mana_a) > strlen($solid_mana_b)){
      	return -1;
      }else{
        return 0;
      }
    }
  }
  private function sortByColor($a, $b){
  	return strcasecmp($a['color'], $b['color']);
  }

  // Export player 1's picks as a string

  public function export($format){
   	$return = "// Generated by MagicDraftSim.com on " . date("M jS, Y g:iA T", $this->timestamp) . "\r";
   	if($format == 'Apprentice'){
   		$return .= "// Magic Apprentice decklist\r\r";
   	}else if($format == 'MWS'){
   		$return .= "// Magic Workshop decklist\r\r";
   	}else if($format == 'html'){
   		$return .= "\r";
   	}else{
   	  //return 'Format not supported';
    }
   	$main = array();
   	$side = array();
    for($pack=1;$pack<=count($this->sets);$pack++){
   		for($player=1;$player<9;$player++){
   	  	for($position=1;$position<=$this->pack_sizes[$pack];$position++){
   	  		if($this->picks[$pack][$player][$position] == 1){
   	  			$incremented = FALSE;
   	  			if($this->cards[$pack][$player][$position]['main_deck'] == 1){
              for($i=0;$i<count($main);$i++){
             	  if( $this->cards[$pack][$player][$position]['name'] == $main[$i]['name']
             	  && $this->cards[$pack][$player][$position]['cardset'] == $main[$i]['cardset']){
                   $main[$i]['quantity'] += 1;
                   $incremented = TRUE;
                 }
             	}
             	if(!$incremented){
             		$index = count($main);
             		$main[$index]['name'] = $this->cards[$pack][$player][$position]['name'];
             		$main[$index]['cardset'] = $this->cards[$pack][$player][$position]['cardset'];
             		$main[$index]['main_deck'] = $this->cards[$pack][$player][$position]['main_deck'];
             		$main[$index]['quantity'] = 1;
             	}
            }else{
              for($i=0;$i<count($side);$i++){
             	  if( $this->cards[$pack][$player][$position]['name'] == $side[$i]['name']
             	  && $this->cards[$pack][$player][$position]['cardset'] == $side[$i]['cardset']){
                   $side[$i]['quantity'] += 1;
                   $incremented = TRUE;
                 }
             	}
             	if(!$incremented){
             		$index = count($side);
             		$side[$index]['name'] = $this->cards[$pack][$player][$position]['name'];
             		$side[$index]['cardset'] = $this->cards[$pack][$player][$position]['cardset'];
             		$side[$index]['main_deck'] = $this->cards[$pack][$player][$position]['main_deck'];
             		$side[$index]['quantity'] = 1;
             	}

            }
   	  	  }
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

  	usort($main, array($this, "sortByName"));
  	usort($side, array($this, "sortByName"));
  	for($i=0;$i<count($main);$i++){
  	  if($format == 'Apprentice'){
  	  	$return .= $main[$i]['quantity'] . " " . $main[$i]['name'] . "\r";
    	}else if($format == 'MWS'){
    		$return .= $main[$i]['quantity'] . " [" . $main[$i]['cardset'] . "] " . $main[$i]['name'] . "\r";
    	}else if($format == 'html'){
    		$return .= $main[$i]['quantity'] . " [" . $main[$i]['cardset'] . "] " . $main[$i]['name'] . "\r";
    	}else{
  	  	$return .= $main[$i]['quantity'] . " " . $main[$i]['name'] . "\r";
      }
  	}
  	for($i=0;$i<count($side);$i++){
  	  if($format == 'Apprentice'){
  	  	$return .= "SB: " . $side[$i]['quantity'] . " " . $side[$i]['name'] . "\r";
    	}else if($format == 'MWS'){
    		$return .= "SB: " . $side[$i]['quantity'] . " [" . $side[$i]['cardset'] . "] " . $side[$i]['name'] . "\r";
    	}else if($format == 'html'){
    		$return .= "SB: " . $side[$i]['quantity'] . " [" . $side[$i]['cardset'] . "] " . $side[$i]['name'] . "\r";
    	}else{
  	  	$return .= "SB: " . $side[$i]['quantity'] . " " . $side[$i]['name'] . "\r";
      }
  	}
  	return $return;
  }

} // Draft class

?>