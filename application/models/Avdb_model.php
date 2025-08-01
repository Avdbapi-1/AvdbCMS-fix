<?php if (!defined('BASEPATH'))
    exit('No direct script access allowed');
#[AllowDynamicProperties]
class Avdb_model extends CI_Model
{
    protected string $api = "https://avdbapi.com/api.php/provide/vod";

    function __construct()
    {
        parent::__construct();
        date_default_timezone_set(ovoo_config('timezone'));
    }

    function get_movies_page($page = 1, $param)
    {
        $data = file_get_contents($this->api . "/?ac=list&pg=".$page.$param);
        $data = json_decode($data, true);

        $response = array();
        $movies = array();
        if (empty($data) || $data['code'] != 1) {
            $response['status'] = 'fail';
        } else {
            $response['status'] = 'success';
            foreach ($data['list'] as $movie) {
                array_push($movies, ['id' => $movie['id'], 'code' => $movie['movie_code']]);
            }
            $response['movies'] = $movies;
        }

        return $response;
    }

    function get_movies_today()
    {
        $data = file_get_contents($this->api . "/?ac=list&h=24");
        $data = json_decode($data, true);

        $response = array();
        if (empty($data) || $data['code'] != 1) {
            $response['status'] = 'fail';
        } else {
            $response['status'] = 'success';
            $pages = [];
            $count = (int) $data['pagecount'];
            for ($i = 1; $i <= $count; $i++) {
                array_push($pages, $i);
            }
            $response['pages'] = $pages;
        }

        return $response;
    }

    function get_movies_all()
    {
        $data = file_get_contents($this->api . "/?ac=list");
        $data = json_decode($data, true);

        $response = array();
        if (empty($data) || $data['code'] != 1) {
            $response['status'] = 'fail';
        } else {
            $response['status'] = 'success';
            $pages = [];
            $count = (int) $data['pagecount'];
            for ($i = 1; $i <= $count; $i++) {
                array_push($pages, $i);
            }
            $response['pages'] = $pages;
        }

        return $response;
    }

    function get_movie_by_id($id)
    {
        $response = array('status' => 'fail');
        try {
            $data = file_get_contents($this->api . "/?ac=detail&ids=" . $id);
            $data = json_decode($data, true);
            if (empty($data) || $data['code'] != 1) {
                $response['status'] = 'fail';
            } else {
                $movie_data = $data['list'][0];
                $msg = $this->insert_or_update_movie($movie_data);
                $response['status'] = 'success';
                $response['msg'] = $msg;
            }
        } catch (Exception $e) {}

        return $response;
    }

    function insert_or_update_movie($data)
    {
        if (empty($data)) {
            $response = "Data error";
        }

        $isExist = $this->common_model->tmdb_exist($data['id']);

        if ($isExist) { // Update
            $row = $this->db->get_where('videos', array('writer' => $data['movie_code']))->row();
            if (!$row) {
                return 'Không tìm thấy videos_id để update cho CODE: '.$data['movie_code'];
            }
            $videos_id = $row->videos_id;
            $this->db->where('videos_id', $videos_id);
            $this->db->delete('video_file');

            $episodes = $data['episodes']['server_data'];
            if (is_array($episodes)) {
                $this->insert_episode($videos_id, $episodes);
                $response = 'ID: '.$data['id'].' CODE: '.$data['movie_code'] . ' => Updated';
            } else {
                $response = 'ID: '.$data['id'].' CODE: '.$data['movie_code'] . ' => Episode Error';
            }
        } else { // Insert
            $actor_ids = $this->update_actors($data['actor']);
            $director_ids = $this->update_directors($data['director']);
            $genres = implode(',', $data['category']);

            $movie_data['tmdbid'] = $data['id'];
            $movie_data['title'] = $data['name'];
            $movie_data['seo_title'] = $data['name'];
            $movie_data['slug'] = $data['slug'];
            $movie_data['description'] = $data['description'];
            $movie_data['runtime'] = $data['time'];
            $movie_data['stars'] = $actor_ids;
            $movie_data['director'] = $director_ids;
            $movie_data['writer'] = $data['movie_code'];
            $movie_data['country'] = $this->country_model->get_country_ids(implode(',', $data['country']));
            $movie_data['genre'] = $this->genre_model->get_genre_ids($genres);
            $movie_data['imdb_rating'] = 'n/a';
            $movie_data['release'] = $data['created_at'];
            $movie_data['video_quality'] = 'HD';
            $movie_data['publication'] = '1';
            $movie_data['enable_download'] = '0';
            $movie_data['trailler_youtube_source'] = '';
            $movie_data['is_paid'] = '0';
            $movie_data['poster_url'] = $data['poster_url'];
            $movie_data['thumb_url'] = $data['thumb_url'];
            $this->db->insert('videos', $movie_data);
            $insert_id = $this->db->insert_id();

            // Update slug
            $slug = url_title($movie_data['slug'], 'dash', TRUE);
            $slug_num = $this->common_model->slug_num('videos', $slug);
            if ($slug_num > 0) {
                $slug = $slug . '-' . $insert_id;
            }
            $this->db->where('videos_id', $insert_id);
            $this->db->update('videos', ['slug' => $slug]);

            // Episodes
            $episodes = $data['episodes']['server_data'];
            $this->insert_episode($insert_id, $episodes);

            $response = 'ID: '.$data['id'].' CODE: '.$data['movie_code'] . ' => Inserted';
        }

        return $response;
    }

    function insert_episode($video_id, $episodes)
    {
        $file_data = array();

        try {
            if (count($episodes) > 1) {
                $seasons = $this->common_model->get_seasons_by_videos_id($video_id);
                if (count($seasons) >= 1) {
                    $season_id = $seasons[0]['seasons_id'];
                } else {
                    $season['videos_id'] = $video_id;
                    $season['seasons_name'] = 'Season 1';
                    $season['order'] = '0';
                    $this->db->insert('seasons', $season);
                    $season_id = $this->db->insert_id();
                }
    
                $this->db->delete('episodes', array('videos_id' => $video_id, 'seasons_id' => $season_id));
    
                foreach ($episodes as $ep) {
                    if ($ep['link_embed'] == '') {
                        continue;
                    }
                    $datetime = date("Y-m-d H:i:s");
                    
                    $episode['videos_id'] = $video_id;
                    $episode['seasons_id'] = $season_id;
                    $episode['episodes_name'] = $ep['slug'];
                    $episode['order'] = '0';
                    $episode['date_added'] = $datetime;
                    $episode['stream_key'] = $this->generate_random_string();
                    $episode['file_source'] = 'embed';
                    $episode['file_url'] = $ep['link_embed'];
                    $episode['source_type'] = 'link';
                    $this->db->insert('episodes', $episode);
    
                }
                $this->db->where('videos_id', $video_id);
                $this->db->update('videos', array(
                    'is_tvseries' => '1',
                    'last_ep_added' => date("Y-m-d H:i:s")
                ));
            } else {
                foreach ($episodes as $ep) {
                    if ($ep['link_embed'] == '') {
                        continue;
                    }
                    $file_data['videos_id'] = (int) $video_id;
                    $file_data['file_source'] = 'embed';
                    $file_data['stream_key'] = $this->generate_random_string();
                    $file_data['source_type'] = 'link';
                    $file_data['file_url'] = $ep['link_embed'];
                    $file_data['label'] = $ep['slug'];
        
                    $this->db->insert('video_file', $file_data);
                }
            }
        } catch (Exception $e) {}
    }

    function update_actors($actors)
    {
        $actors = implode(',', $actors);
        $ids = $this->common_model->get_star_ids('actor', $actors);
        return $ids;
    }
    function update_directors($directors)
    {
        $directors = implode(',', $directors);
        $ids = $this->common_model->get_star_ids('director', $directors);
        return $ids;
    }
    function generate_random_string($length = 12)
    {
        $str = "";
        $characters = array_merge(range('a', 'z'), range('0', '9'));
        $max = count($characters) - 1;
        for ($i = 0; $i < $length; $i++) {
            $rand = mt_rand(0, $max);
            $str .= $characters[$rand];
        }
        return $str;
    }

    // --- HÀM HELPER: XỬ LÝ CHÍNH CHO VIỆC CRAWL TỪNG TRANG ---
    private function _crawl_single_page($api_url) {
        set_time_limit(300); // 5 phút cho mỗi trang
        ini_set('memory_limit', '512M');

        $data = @file_get_contents($api_url);
        if ($data === false || ($data = json_decode($data, true)) === null || !isset($data['list']) || empty($data['list'])) {
            return ['status' => 'fail', 'log' => ['API lỗi hoặc trang không có dữ liệu.'], 'has_more' => false];
        }
        
        $log = [];
        foreach($data['list'] as $movie) {
            try {
                $msg = $this->insert_or_update_movie($movie);
                if (preg_match('/ID: (.*?) CODE: (.*?) => (.*)/', $msg, $m)) {
                    $log[] = 'ID: ' . $m[1] . ' | CODE: ' . $m[2] . ' | ' . $m[3];
                } else {
                    $log[] = $msg;
                }
            } catch (Exception $e) {
                $log[] = 'Lỗi xử lý phim: ' . $e->getMessage();
            }
        }
        
        $page = isset($data['page']) ? (int)$data['page'] : 1;
        $pagecount = isset($data['pagecount']) ? (int)$data['pagecount'] : $page;

        return [
            'status' => 'success',
            'log' => $log,
            'has_more' => ($page < $pagecount),
            'page' => $page,
            'pagecount' => $pagecount
        ];
    }

    // --- CRAWL THEO CATEGORY ---
    function crawl_by_category($category_id) {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $api_url = $this->api . "/?ac=detail&t=" . $category_id . "&pg=" . $page;
        return $this->_crawl_single_page($api_url);
    }

    // --- CRAWL ALL AUTO ---
    public function crawl_all_auto() {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $api_url = $this->api . "/?ac=detail&pg=" . $page;
        return $this->_crawl_single_page($api_url);
    }

    // --- CRAWL PAGE RANGE ---
    public function crawl_page_range($start, $end) {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : $start;
        if ($page > $end) {
            return ['status' => 'fail', 'log' => ['Đã hoàn thành crawl đến trang ' . $end], 'has_more' => false];
        }
        $api_url = $this->api . "/?ac=detail&pg=" . $page;
        return $this->_crawl_single_page($api_url);
    }

    // --- CRAWL BY KEYWORD ---
    public function crawl_by_keyword($keyword) {
        $page = isset($_POST['page']) ? (int)$_POST['page'] : 1;
        $api_url = $this->api . "/?ac=detail&wd=" . urlencode($keyword) . "&pg=" . $page;
        return $this->_crawl_single_page($api_url);
    }
    
    // --- CRAWL BY ID (không thay đổi logic) ---
    public function crawl_by_id($id) {
        $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : 50;
        $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
        set_time_limit(0);
        ini_set('memory_limit', '512M');
        $api_url = $this->api . "/?ac=detail&ids=" . $id;
        $data = @file_get_contents($api_url);
        $log = [];
        if ($data === false) return ['status'=>'fail','log'=>['Lỗi API'],'has_more'=>false,'done'=>0,'total'=>0];
        $data = json_decode($data, true);
        if (empty($data) || !isset($data['list'])) return ['status'=>'fail','log'=>['API trả về không hợp lệ'],'has_more'=>false,'done'=>0,'total'=>0];
        $movies = $data['list'];
        $total = count($movies);
        $done = 0;
        $has_more = $this->process_batch($movies, $offset, $batch_size, $log, $done, $total);
        return [
            'status' => 'success',
            'log' => $log,
            'has_more' => $has_more,
            'done' => $done,
            'total' => $total
        ];
    }
}

