<?php

    /**
     * Routes for /api/* URLs.  Handles JSON/CSV API requests.
     *
     * @package UserFrosting
     * @author Alex Weissman
     */

    use \UserFrosting as UF;
    use \Illuminate\Database\Capsule\Manager as Capsule;
    
    global $app;
    
    /**
     * Returns a list of Users
     *
     * Generates a list of users, optionally paginated, sorted and/or filtered.
     * This page requires authentication.
     * Request type: GET
     */
    $app->get('/api/users/?', function () use ($app) {
        $get = $app->request->get();

        $size = isset($get['size']) ? $get['size'] : null;
        $page = isset($get['page']) ? $get['page'] : null;
        $sort_field = isset($get['sort_field']) ? $get['sort_field'] : "user_name";
        $sort_order = isset($get['sort_order']) ? $get['sort_order'] : "asc";
        $filters = isset($get['filters']) ? $get['filters'] : [];
        $format = isset($get['format']) ? $get['format'] : "json";
        $primary_group_name = isset($get['primary_group']) ? $get['primary_group'] : null;

        // Optional filtering by primary group
        if ($primary_group_name){
            $primary_group = UF\Group::where('name', $primary_group_name)->first();

            if (!$primary_group)
                $app->notFound();

            // Access-controlled page
            if (!$app->user->checkAccess('uri_group_users', ['primary_group_id' => $primary_group->id])){
                $app->notFound();
            }

            $userQuery = new UF\UF\User;
            $userQuery = $userQuery->where('primary_group_id', $primary_group->id);

        } else {
            // Access-controlled page
            if (!$app->user->checkAccess('uri_users')){
                $app->notFound();
            }

            $userQuery = new UF\User;
        }

        // Count unpaginated total
        $total = $userQuery->count();

        // Exclude fields
        $userQuery = $userQuery
                ->exclude(['password', 'secret_token']);

        //Capsule::connection()->enableQueryLog();

        // Get unfiltered, unsorted, unpaginated collection
        $user_collection = $userQuery->get();

        // Load recent events for all users and merge into the collection.  This can't be done in one query,
        // at least not efficiently.  See http://laravel.io/forum/04-05-2014-eloquent-eager-loading-to-limit-for-each-post
        $last_sign_in_times = $user_collection->getRecentEvents('sign_in');
        $last_sign_up_times = $user_collection->getRecentEvents('sign_up', 'sign_up_time');

        // Apply filters
        foreach ($filters as $name => $value){
            // For date filters, search for weekday, month, or year
            if ($name == 'last_sign_in_time') {
                $user_collection = $user_collection->filterRecentEventTime('sign_in', $last_sign_in_times, $value);
            } else if ($name == 'sign_up_time') {
                $user_collection = $user_collection->filterRecentEventTime('sign_up', $last_sign_up_times, $value, "Unknown");
            } else {
                $user_collection = $user_collection->filterTextField($name, $value);
            }
        }

        // Count filtered results
        $total_filtered = count($user_collection);

        // Sort
        if ($sort_order == "desc")
            $user_collection = $user_collection->sortByDesc($sort_field, SORT_NATURAL|SORT_FLAG_CASE);
        else
            $user_collection = $user_collection->sortBy($sort_field, SORT_NATURAL|SORT_FLAG_CASE);

        // Paginate
        if ( ($page !== null) && ($size !== null) ){
            $offset = $size*$page;
            $user_collection = $user_collection->slice($offset, $size);
        }

        $result = [
            "count" => $total,
            "rows" => $user_collection->values()->toArray(),
            "count_filtered" => $total_filtered
        ];

        //$query = Capsule::getQueryLog();

        if ($format == "csv"){
            $settings = http_build_query($get);
            $date = date("Ymd");
            $app->response->headers->set('Content-Disposition', "attachment;filename=$date-users-$settings.csv");
            $app->response->headers->set('Content-Type', 'text/csv; charset=utf-8');
            $keys = $user_collection->keys()->toArray();
            echo implode(array_keys($result['rows'][0]), ",") . "\r\n";
            foreach ($result['rows'] as $row){
                echo implode($row, ",") . "\r\n";
            }
        } else {
            // Be careful how you consume this data - it has not been escaped and contains untrusted user-supplied content.
            // For example, if you plan to insert it into an HTML DOM, you must escape it on the client side (or use client-side templating).
            $app->response->headers->set('Content-Type', 'application/json; charset=utf-8');
            echo json_encode($result, JSON_PRETTY_PRINT);
        }
    });
    