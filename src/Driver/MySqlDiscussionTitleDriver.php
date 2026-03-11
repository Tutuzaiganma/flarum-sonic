<?php
namespace GaNuongLaChanh\Sonic\Driver;

use Flarum\Post\Post;
use Flarum\Discussion\Discussion;
use Illuminate\Contracts\Container\Container;
use Flarum\Settings\SettingsRepositoryInterface;
use GaNuongLaChanh\Sonic\Support\SearchTextNormalizer;
class MySqlDiscussionTitleDriver
{
    /**
     * @var int[]
     */
    protected array $orderedDiscussionIds = [];

    public function __construct(Container $container, SettingsRepositoryInterface $settings)
    {
        $this->container = $container;
        $this->settings = $settings;
    }

    /**
     * @return int[]
     */
    public function getOrderedDiscussionIds(): array
    {
        return $this->orderedDiscussionIds;
    }
    /**
     * {@inheritdoc}
     */
    public function match($string)
    {
        $string = trim((string) $string);
        if ($string === '') {
            $this->orderedDiscussionIds = [];
            return [];
        }

        $normalizedString = SearchTextNormalizer::normalize($string);
        $terms = SearchTextNormalizer::extractTerms($string);
        $relevantPostIds = [];
        $discussionScores = [];
        // 1) Search in discussion title first
        $titleQuery = Discussion::where("is_approved", 1)
            ->where("is_private", 0)
            ->whereNull('hidden_at')
            ->where('comment_count', '>', 0)
            ->where(function ($query) use ($string, $normalizedString, $terms) {
                $query->where('title', 'like', '%' . $string . '%');
                if ($normalizedString !== '' && $normalizedString !== $string) {
                    $query->orWhere('title', 'like', '%' . $normalizedString . '%');
                }
                if (count($terms) > 0) {
                    $query->orWhere(function ($termQuery) use ($terms) {
                        foreach ($terms as $term) {
                            $termQuery->where('title', 'like', '%' . $term . '%');
                        }
                    });
                }
            })
            ->limit(20);
        $discussionIds = $titleQuery->pluck('id','first_post_id');

        foreach ($discussionIds as $postId => $discussionId) {
            $relevantPostIds[$discussionId][] = $postId;
            $discussionScores[$discussionId] = ($discussionScores[$discussionId] ?? 0) + 100;
        }
        
        // 2) Then serch in post body by sonic
        $locale = $this->settings->get('ganuonglachanh-sonic.locale','eng');
        $locale = $locale === '' ? 'eng' : $locale;
        $password = $this->settings->get('ganuonglachanh-sonic.password','SecretPassword');
        $password = $password === '' ? 'SecretPassword' : $password;
        $host = $this->settings->get('ganuonglachanh-sonic.host','127.0.0.1');
        $host = $host === '' ? '127.0.0.1' : $host;
        $port = intval($this->settings->get('ganuonglachanh-sonic.port',1491));
        $port = $port === 0 ? 1491 : $port;
        $timeout = intval($this->settings->get('ganuonglachanh-sonic.timeout',30));
        $timeout = $timeout === 0 ? 30 : $timeout;
        //echo $string .PHP_EOL;
        $search = new \Psonic\Search(new \Psonic\Client($host, $port, $timeout));
        $search->connect($password);
        $searchTerms = array_values(array_unique(array_filter(array_merge([$string, $normalizedString], $terms), function ($term) {
            return trim((string) $term) !== '';
        })));
        $res = [];
        foreach (array_unique($searchTerms) as $term) {
            $termResult = $search->query('postCollection', 'flarumBucket', $term, 20, 0, $locale);
            if (is_array($termResult) && count($termResult) > 0) {
                $res = array_merge($res, $termResult);
            }
        }
        $res = array_values(array_unique($res));
        // you should be getting an array of object keys which matched with the term $string
        $search->disconnect();
        
        if (is_array($res) && count($res) > 0) {
            //var_dump($res);
            //$discussionIds = Post::select('id','discussion_id')
            $discussionIds = Post::where('type','=', 'comment')
            ->where('is_approved', 1)
            ->where('is_private', 0)
            ->whereNull('hidden_at')
            ->whereIn('id', $res)
            ->limit(20)
            ->pluck('discussion_id', 'id');
            foreach ($discussionIds as $postId => $discussionId) {
                if (!isset($relevantPostIds[$discussionId])) {
                    $relevantPostIds[$discussionId] = [];
                }
                if (!in_array($postId, $relevantPostIds[$discussionId], true)) {
                    $relevantPostIds[$discussionId][] = $postId;
                }
                $discussionScores[$discussionId] = ($discussionScores[$discussionId] ?? 0) + 10;
            }
            
        }

        arsort($discussionScores);
        $this->orderedDiscussionIds = array_map('intval', array_keys($discussionScores));

        return $relevantPostIds;
    }
}
