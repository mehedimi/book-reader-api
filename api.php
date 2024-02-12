<?php
/*
 * Plugin Name: Book Reader API
 */

use Mehedi\WPQueryBuilder\DB;
use Mehedi\WPQueryBuilder\Query\Builder;
use Mehedi\WPQueryBuilder\Query\Join;
use Mehedi\WPQueryBuilder\Relations\WithOne;
use Mehedi\WPQueryBuilderExt\Relations\WithTaxonomy;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__.'/vendor/autoload.php';

class BookReaderController extends WP_REST_Controller
{
    public $namespace = '/library/v1';

    public function register_routes()
    {
        register_rest_route($this->namespace, '/books', [
            'methods' => 'GET',
            'callback' => [$this, 'index'],
        ]);

        register_rest_route($this->namespace, '/(?P<taxonomy>authors|categories)', [
            'methods' => 'GET',
            'callback' => [$this, 'taxonomies'],
        ]);

        register_rest_route($this->namespace, '/taxonomies/(?P<id>[\\d]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'taxonomy'],
        ]);
    }

    public function index(WP_REST_Request $request)
    {
        $s = $request->get_param('s');
        $termId = $request->get_param('termId');
        $perPage = $request->get_param('perPage') ?? 20;
        $page = $request->get_param('page') ?? 1;

        $query = DB::table('posts')
            ->where('post_type', 'books')
            ->where('post_status', 'publish')
            ->withRelation(new WithTaxonomy('authors'), function (WithTaxonomy /** @var Builder $relation */ $relation) {
                $relation->taxonomy('author');
            })
            ->withOne('thumbnail', function (WithOne $thumbnailQuery) {
                $thumbnailQuery->from('posts')->where('post_type', 'attachment');
            }, 'post_parent')
            ->skip(($page - 1) * $perPage)
            ->limit($perPage);

        if ($s) {
            $query->where('post_title', 'like', "%$s%");
        }

        if ($termId) {
            $query->join('term_relationships', 'posts.ID', '=', 'term_relationships.object_id')
                ->join('term_taxonomy', function (Join $join) use ($termId) {
                    $join->on('term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id')
                        ->where('term_taxonomy.term_id', $termId);
                });
        }

        return rest_ensure_response([
            'data' => array_map(function ($post) {
                return [
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'authors' => array_map(function ($author) {
                        return [
                            'id' => $author->term_id,
                            'name' => $author->name,
                        ];
                    }, $post->authors),
                    'thumbnail' => $post->thumbnail->guid ?? null,
                ];
            }, $query->get()),
        ]);
    }

    public function taxonomies(WP_REST_Request $request)
    {
        $search = $request->get_param('s');
        $taxonomy_type = $request->get_param('taxonomy') === 'authors' ? 'author' : 'category';

        $query = DB::table('term_taxonomy')
            ->join('terms', 'term_taxonomy.term_id', '=', 'terms.term_id')
            ->where('taxonomy', $taxonomy_type)
            ->where('count', '>', 0)
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%$search%");
        }

        return [
            'data' => array_map(function ($item) {
                return [
                    'id' => $item->term_id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'count' => $item->count,
                ];
            }, $query->get()),
        ];
    }

    public function taxonomy(WP_REST_Request $request)
    {
        $termId = $request->get_param('id');
        $item = DB::table('terms')->where('term_id', $termId)->first();

        if (! $item) {
            return new WP_REST_Response(['message' => 'No term found!'], 404);
        }

        return [
            'data' => [
                'id' => $item->term_id,
                'name' => $item->name,
                'slug' => $item->slug,
            ],
        ];
    }
}

add_action('rest_api_init', [new BookReaderController(), 'register_routes']);
