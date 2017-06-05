<?php
/*
  +-------------------------------------------------------------------------+
  | Copyright (C) 2004 Juan Luis Frances Jimenez						    |
  | Copyright 2010-2017, Davide Franco                                      |
  |                                                                         |
  | This program is free software; you can redistribute it and/or           |
  | modify it under the terms of the GNU General Public License             |
  | as published by the Free Software Foundation; either version 2          |
  | of the License, or (at your option) any later version.                  |
  |                                                                         |
  | This program is distributed in the hope that it will be useful,         |
  | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
  | GNU General Public License for more details.                            |
  +-------------------------------------------------------------------------+
*/
 session_start();
 include_once( 'core/global.inc.php' );
 
 // Initialise view and model
 $view     = new CView();
 $dbSql     = null;

try {
    $dbSql = new Bweb($view);

    $jobs      = new Jobs_Model();
    $catalog   = new Database_Model();
    $clients   = new Clients_Model(); 
    $filesets  = new FileSets_Model();

    require_once('core/const.inc.php');

    // Custom period for dashboard
    $no_period = array(FIRST_DAY, NOW);
    $last_day = array(LAST_DAY, NOW);

    // Default period (last day)
    $custom_period = $last_day;
    $selected_period = 'last_day';

    if (isset($_POST['period_selector'])) {
        $selected_period = CHttpRequest::get_Value('period_selector');
        $view->assign('custom_period_list_selected', $selected_period);

        switch($selected_period) {
            case 'last_day':
                $custom_period = array( LAST_DAY, NOW);
                break;
            case 'last_week':
                $custom_period = array( LAST_WEEK, NOW);
                break;
            case 'last_month':
                $custom_period = array( LAST_MONTH, NOW);
                break;
            case 'since_bot':
                $custom_period = $no_period;
                break;
        } // end switch
    } else {
        $view->assign('custom_period_list_selected', $selected_period);
    }

    $custom_period_list = array( 'last_day' => 'Last 24 hours',
                                'last_week' => 'Last week',
                                'last_month' => 'Last month',
                                'since_bot' => 'Since BOT');

    $view->assign('custom_period_list', $custom_period_list);

    // Set period start - end for widget header
    $view->assign('literal_period', strftime("%a %e %b %Y", $custom_period[0]).' to ' . strftime("%a %e %b %Y", $custom_period[1]));

    // Running, completed, failed, waiting and canceled jobs status over last 24 hours
    $view->assign('running_jobs', $jobs->count_Jobs( $custom_period, 'running'));
    $view->assign('completed_jobs', $jobs->count_Jobs( $custom_period, 'completed'));
    $view->assign('failed_jobs', $jobs->count_Jobs( $custom_period, 'failed'));
    $view->assign('waiting_jobs', $jobs->count_Jobs( $custom_period, 'waiting'));
    $view->assign('canceled_jobs', $jobs->count_Jobs( $custom_period, 'canceled'));

    // Stored files number
    $view->assign('stored_files', CUtils::format_Number($jobs->getStoredFiles($no_period)));
 
    // Overall stored bytes
    $view->assign('stored_bytes', CUtils::Get_Human_Size($jobs->getStoredBytes($no_period)));

    // Database size
    $view->assign('database_size', $catalog->get_Size( $dbSql->catalog_current_id ));
 
    // Total bytes and files stored over the last 24 hours
    $view->assign('bytes_last', CUtils::Get_Human_Size($jobs->getStoredBytes($custom_period)));
    $view->assign('files_last', CUtils::format_Number($jobs->getStoredFiles($custom_period)));

    // Number of clients
    $view->assign('clients', $clients->count());

    // Defined Jobs and Filesets
    $view->assign('defined_filesets', $filesets->count());
    $view->assign('defined_jobs', $jobs->count_Job_Names());

    // Incremental, Differential and Full jobs over the last 24 hours
    $view->assign('incr_jobs', $jobs->count_Jobs( $custom_period, null, J_INCR));
    $view->assign('diff_jobs', $jobs->count_Jobs( $custom_period, null, J_DIFF));
    $view->assign('full_jobs', $jobs->count_Jobs( $custom_period, null, J_FULL));

    // Volumes disk usage
    $volumes = new Volumes_Model();
    $volumes_size = $volumes->getDiskUsage();
    $view->assign('volumes_size', CUtils::Get_Human_Size($volumes_size));

    // Pools count
    $pools = new Pools_Model();
    $view->assign('pools_nb', $pools->count());

    // Backup Job list
    $view->assign('jobs_list', $jobs->get_Jobs_List());
 
    // Clients list
    $view->assign('clients_list', $clients->getClients());

    // Count volumes
    $view->assign('volumes_nb', $volumes->count());

    // ==============================================================
    // Last period <Job status graph>
    // ==============================================================
    $jobs_status = array('Running', 'Completed', 'Waiting', 'Failed', 'Canceled');
    $jobs_status_data = array();

    foreach ($jobs_status as $status) {
        $jobs_count = $jobs->count_Jobs( $custom_period, strtolower($status));
        $jobs_count = 1;
        $jobs_status_data[] = array($status, $jobs_count );
    }
     
    $graph = new CGraph("dashboard-graph01.jpg");
    $graph->SetData($jobs_status_data, 'pie');
    $graph->setPieLegendColors(array( 'gray', 'green','blue', 'red', 'orange'));

    // Graph rendering
    $view->assign('graph_jobs', $graph->Render());

    unset($graph);

    // ==============================================================
    // Volumes per pool widget
    // ==============================================================

    $vols_by_pool = array();
    $graph = new CGraph("dashboard-graph02.jpg");
    $max_pools = '9';
    $table_pool = 'Pool';
    $limit = '';
    $sum_vols = '';

    // Count defined pools in catalog
    $pools_count = $pools->count();

    // Display 9 biggest pools and rest of volumes in 10th one display as Other
    if ($pools_count > $max_pools) {
        /*
        if ( $pools->get_driver_name() == 'pgsql') {
            $limit = $max_pools . 'OFFSET ' . ($pools_count - $max_pools);
        } else {
            $limit = $max_pools . ',' . ($pools_count - $max_pools);
        }
         */
        $query = array( 'table' => $table_pool,
                       'fields' => array('SUM(numvols) AS sum_vols'),
                       'limit' => array( 'offset' => ($pools_count - $max_pools), 'count' => $pools_count),
                       'groupby' => 'name');
        
        $result = $pools->run_query(CDBQuery::get_Select($query, $pools->get_driver_name()));
        $sum_vols = $result->fetch();
    } else {
        $limit = $pools_count;
    }

    $query = array('table' => $table_pool, 'fields' => array('poolid,name,numvols'), 'orderby' => 'numvols DESC', 'limit' => $max_pools);
    $result = $pools->run_query(CDBQuery::get_Select($query));

    foreach ($result as $pool) {
        $vols_by_pool[] = array($pool['name'], $pool['numvols']);
    }

    if ($pools_count > $max_pools) {
        $vols_by_pool[] = array('Others', $sum_vols['sum_vols']);
    }

    $graph->SetData($vols_by_pool, 'pie');

    // Graph rendering
    $view->assign('graph_pools', $graph->Render());

    unset($graph);

    // ==============================================================
    // Last 7 days stored Bytes widget
    // ==============================================================
    $days_stored_bytes = array();
    $days = DateTimeUtil::getLastDaysIntervals(7);

    foreach ($days as $day) {
        $days_stored_bytes[] = array( date("m-d", $day['start']), $jobs->getStoredBytes(array($day['start'], $day['end'])));
    }

    $graph = new CGraph("dashboard-graph03.jpg");
    $graph->SetData($days_stored_bytes, 'bars', true);

    // Graph rendering
    $view->assign('graph_stored_bytes', $graph->Render());
     
    // ==============================================================
    // Last 7 days Stored Files widget
    // ==============================================================
    $days_stored_files = array();
    $days = DateTimeUtil::getLastDaysIntervals(7);

    foreach ($days as $day) {
        $days_stored_files[] = array( date("m-d", $day['start']), $jobs->getStoredFiles(array($day['start'], $day['end'])));
    }

    $graph = new CGraph("dashboard-graph04.jpg");
    $graph->SetData($days_stored_files, 'bars');

    // Graph rendering
    $view->assign('graph_stored_files', $graph->Render());

    unset($graph);

    // ==============================================================
    // Last used volumes widget
    // ==============================================================

    $last_volumes = array();

    // Building SQL statment
    $where = array();
    $tmp   = "(Media.Volstatus != 'Disabled') ";

    switch ($volumes->get_driver_name()) {
        case 'mysql':
        case 'pgsql':
            $tmp .= "AND (Media.LastWritten IS NOT NULL)";
            break;
        case 'sqlite':
            $tmp .= "AND (Media.Lastwritten != 0)";
    }
     
    $where[] = $tmp;

    $statment = array(    'table'     => 'Media',
                       'fields'     => array('Media.MediaId', 'Media.Volumename', 'Media.Lastwritten', 'Media.VolStatus', 'Media.VolJobs', 'Pool.Name AS poolname'),
                       'join'         => array('table' => 'Pool', 'condition' => 'Media.PoolId = Pool.poolid'),
                       'where'     => $where,
                       'orderby'     => 'Media.Lastwritten DESC',
                       'limit'     => '10');

    // Run the query
    $result     = $volumes->run_query(CDBQuery::get_Select($statment));

    foreach ($result as $volume) {
       if($volume['lastwritten'] != '0000-00-00 00:00:00') {
          $volume['lastwritten'] = date( $dbSql->datetime_format, strtotime($volume['lastwritten']));
       }else {
          $volume['lastwritten'] = 'n/a';
       }
       $last_volumes[] = $volume;
    }

    $view->assign('volumes_list', $last_volumes);
     
} catch (Exception $e) {
    CErrorHandler::displayError($e);
}
 
 // Set page name
 $current_page = 'Dashboard';
 $view->assign('page_name', $current_page);
 
 // Render template
 $view->render('index.tpl');
