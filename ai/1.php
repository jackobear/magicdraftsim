<?php

// Bot configuration for MainBot

$ai_config['bot_name'] = "MainBot";
$ai_config['low_creature_threshold'] = 10;    // How many picks deep before considering creatures more
$ai_config['low_creature_proportion'] = 1/3;  // Minimum creature proportion before considering creature more
$ai_config['low_creature_modifier'] = 2;     // Maximum rating modifier (end of pack 3)
$ai_config['color_threshold'] = 3;            // How many picks deep before considering color more
$ai_config['color_modifier'] = 2.8;            // Maximum additional rating modifier for matching color (top 2 colors)
$ai_config['color_modifier_min'] = 1;      // Minimum rating modifier for matching color (top 2 colors)
$ai_config['splashable_modifier'] = 1;       // Counteracts color modifier when there is only one off-color mana
$ai_config['high_mana_curve_avg'] = 4;        // How high the average mana cost must be before valuing cards under 3
$ai_config['high_mana_curve_modifier'] = 2;  // Maximum rating modifier for lower cost spells
$ai_config['high_mana_curve_threshold'] = 20; // How many picks deep before considering cheaper spells more
$ai_config['target_avg_cmc'] = 3;           // Cards that deviate from this cmc will be adjusted accordingly
$ai_config['target_avg_cmc_modifier'] = 0.3;   // Multiplied by the cmc difference from the target
$ai_config['creature_modifier'] = 0;         // Every spell will be adjusted based on whether it is a creature
$ai_config['colorless_threshold'] = 4;        // How many picks deep until colorlessness isn't extra valuable
$ai_config['colorless_modifier'] = 1;        // Maximum rating modifier for colorless spells in the first few picks
$ai_config['color_weight'] = 3;               // Maximum multiplier for weighting a color's value in regards to avg_pick
$ai_config['uncommon_modifier'] = 0.1;         // Maximum modifier for uncommon spells (not dependent on pick number)
$ai_config['rare_modifier'] = 0.2;             // Maximum modifier for rare spells
$ai_config['mythic_modifier'] = 0.3;         // Maximum modifier for mythic spells
$ai_config['noise_multiplier'] = 0.2;          // Percentage of how much to modify each modifier when the bot is created

?>