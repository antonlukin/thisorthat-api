<?php
/**
 * Model for /getFavorite API method
 *
 * @author      Anton Lukin <anton@lukin.me>
 * @license     MIT License
 * @since       2.0
 */

namespace methods;


/**
 * Get certain user favorite items
 */
class getFavorite extends \engine
{
    /**
     * Select votes from database by item id
     */
    private static function select_votes($item_id)
    {
        $database = self::get_database();

        // The query to get only certain user non-answered items from given section
        $query = "SELECT
            IFNULL(SUM(vote = 'left'), 0) first_vote,
            IFNULL(SUM(vote = 'right'), 0) last_vote
            FROM views WHERE item_id = :item_id GROUP BY item_id";

        $select = $database->prepare($query);
        $select->execute(compact('item_id'));

        return $select->fetch();
    }


    /**
     * Get favorite items votes
     */
    private static function get_votes($favorite)
    {
        $redis = parent::get_redis();

        foreach ($favorite as $id => &$item) {
            // Get votes from redis by id
            $votes = $redis->get(parent::$redis_prefix . $id);

            if ($votes === false) {
                $votes = self::select_votes($id);

                if ($votes === false) {
                    $votes = array_fill_keys(['first_vote', 'last_vote'], 0);
                }

                // Set votes to redis
                $redis->set(parent::$redis_prefix . $id, array_map('intval', $votes));
            }

            $item = $item + $votes;
        }

        return $favorite;
    }


    /**
     * Get favorite items
     */
    private static function get_favorite($user_id, $limit, $offset)
    {
        $database = parent::get_database();

        // The query to get only certain user favorite items
        $query = "SELECT items.id, items.first_text, items.last_text, items.status
            FROM favorite
            LEFT JOIN items ON items.id = favorite.item_id
            WHERE items.user_id = :user_id
            LIMIT :limit OFFSET :offset";

        $select = $database->prepare($query);
        $select->execute(compact('user_id', 'limit', 'offset'));

        return $select->fetchAll(\PDO::FETCH_UNIQUE);
    }


    /**
     * Get total count of user favorite items
     * For some reason it works faster than SQL_CALC_FOUND_ROWS
     */
    private static function calc_count($user_id)
    {
        $database = parent::get_database();

        // Get only count of user favorite items
        $select = $database->prepare("SELECT COUNT(*) FROM favorite WHERE user_id = :user_id");
        $select->execute(compact('user_id'));

        return $select->fetchColumn();
    }


    /**
     * Model entry point
     */
    public static function run_task()
    {
        // Load engine from parent class
        parent::load_config();

        // Try to authenticate user
        $user_id = parent::authorize_user();

        // Get limit parameter
        // Numeric range 1-100 and 30 by default
        $limit = parent::get_parameter('limit', '^[1-9][0-9]?$|^100$', 30);

        // Get offset parameter
        $offset = parent::get_parameter('offset', '^[0-9]+$', 0);

        // Get favorite query
        $favorite = self::get_favorite($user_id, $limit, $offset);

        // Get favorite votes
        $favorite = self::get_votes($favorite);

        // Get total count
        $total = self::calc_count($user_id);

        if ($total === false) {
            $total = 0;
        }

        $total = intval($total);

        parent::show_success(compact('favorite', 'total'));
    }
}
