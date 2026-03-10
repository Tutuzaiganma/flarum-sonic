<?php
namespace GaNuongLaChanh\Sonic\Gambit;

use Flarum\Search\SearchState;
use GaNuongLaChanh\Sonic\Driver\MySqlDiscussionTitleDriver;
use GaNuongLaChanh\Sonic\Support\SearchTextNormalizer;
use Flarum\Search\GambitInterface;
use Flarum\Post\Post;
use Illuminate\Database\Query\Expression;

class TitleGambit implements GambitInterface
{
    /**
     * @var MySqlDiscussionTitleDriver
     */
    protected $titleGambit;

    /**
     * @param MySqlDiscussionTitleDriver $titleGambit
     */
    public function __construct(MySqlDiscussionTitleDriver $titleGambit)
    {
        $this->titleGambit = $titleGambit;
    }

    /**
     * {@inheritdoc}
     */
    public function apply(SearchState $search, $bit)
    {
        $bit = trim((string) $bit);
        if ($bit === '') {
            return $search;
        }

        // Keep the original query for symbol-attached terms (e.g. [WakuWaku]),
        // while still using a normalized version in the driver as fallback.
        $normalizedBit = SearchTextNormalizer::normalize($bit);
        if ($normalizedBit === '') {
            return $search;
        }

        $query = $search->getQuery();
        $grammar = $query->getGrammar();

        $relevantPostIds = $this->titleGambit->match($bit);
        $discussionIds = array_keys($relevantPostIds);
        $postIdsArr = array_values($relevantPostIds);
        $postIds = array();
        array_walk_recursive($postIdsArr,function($v) use (&$postIds){ $postIds[] = $v; }); // flatten array
        $subquery = Post::whereVisibleTo($search->getActor())
            ->select(['id as most_relevant_post_id','discussion_id'])
            ->whereIn('posts.id', $postIds);

        $query
            ->addSelect('posts_ft.most_relevant_post_id')
            ->join(
                new Expression('(' . $subquery->toSql() . ') ' . $grammar->wrapTable('posts_ft')),
                'posts_ft.discussion_id',
                '=',
                'discussions.id'
            )
            ->groupBy('discussions.id')
            ->addBinding($subquery->getBindings(), 'join');


        $search->getQuery()->whereIn('discussions.id', $discussionIds);
        //$search->setDefaultSort(['id' => $discussionIds]);
        $search->setDefaultSort(['discussions.id' => 'desc']);
    }
}
