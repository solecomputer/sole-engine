<?php
   
                                        
  
                                                                             
                                                                           
                                                                         
  
                                                                               
                                                                           
                                                              
                                      
                                              
  
                                                                                
                                                                         
                                                                               
                                                                     
  
                                                                        
                                                                            
                                                                         
   

declare(strict_types=1);

namespace SoleEngineWP;

                                                                                
                                                 
if (!\defined("WP_UNINSTALL_PLUGIN")) {
    exit;
}

global $wpdb;

                                                                               
                                                                 
                                                                            
                                          
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
        $wpdb->esc_like("sole_engine_") . "%",
        $wpdb->esc_like("_transient_sole_engine_") . "%",
        $wpdb->esc_like("_transient_timeout_sole_engine_") . "%"
    )
);

                                                                               
                                                  
foreach (
    [
        "sole_engine_bulk_index_batch",
        "sole_engine_reindex_poll",
        "sole_engine_space_poll",
    ] as $sole_engine_hook
) {
    \wp_clear_scheduled_hook($sole_engine_hook);
}

                                                                       
\delete_post_meta_by_key("sole_engine_indexed_hash");
