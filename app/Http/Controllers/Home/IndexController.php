<?php

namespace App\Http\Controllers\Home;

use Agent;
use App;
use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\Store;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Note;
use App\Models\SocialiteUser;
use App\Models\Tag;
use Illuminate\Http\Request;
use Str;

class IndexController extends Controller
{
    public function index()
    {
        // 获取文章列表数据
        $articles = Article::select(
                'id', 'category_id', 'title',
                'slug', 'author', 'description',
                'cover', 'is_top', 'created_at'
            )
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title'       => config('bjyblog.head.title'),
            'keywords'    => config('bjyblog.head.keywords'),
            'description' => config('bjyblog.head.description'),
        ];
        $assign = [
            'category_id'  => 'index',
            'articles'     => $articles,
            'head'         => $head,
            'tagName'      => '',
        ];

        return view('home.index.index', $assign);
    }

    public function article(Article $article, Request $request, Comment $commentModel)
    {
        $article->visits()->increment();

        // 获取上一篇
        $prev = Article::select('id', 'title', 'slug')
            ->orderBy('created_at', 'desc')
            ->where('id', '<', $article->id)
            ->limit(1)
            ->first();

        // 获取下一篇
        $next = Article::select('id', 'title', 'slug')
            ->orderBy('created_at', 'asc')
            ->where('id', '>', $article->id)
            ->limit(1)
            ->first();

        // 获取评论
        $comment     = $commentModel->getDataByArticleId($article->id);
        $category_id = $article->category->id;

        /** @var \App\Models\SocialiteUser $socialiteUser */
        $socialiteUser = auth()->guard('socialite')->user();

        if ($socialiteUser === null) {
            $is_liked = false;
        } else {
            $is_liked = $socialiteUser->hasLiked($article);
        }

        $likes       = $article->likers()->get();
        $assign      = compact('category_id', 'article', 'prev', 'next', 'comment', 'is_liked', 'likes');

        return view('home.index.article', $assign);
    }

    public function category(Category $category)
    {
        // 获取分类下的文章
        $articles = $category->articles()
            ->orderBy('created_at', 'desc')
            ->with('tags')
            ->paginate(10);
        // 为了和首页共用 html ； 此处手动组合分类数据
        if ($articles->isNotEmpty()) {
            $articles->setCollection(
                collect(
                    $articles->items()
                )->map(function ($v) use ($category) {
                    $v->category = $category;

                    return $v;
                })
            );
        }

        $head = [
            'title'       => $category->name,
            'keywords'    => $category->keywords,
            'description' => $category->description,
        ];
        $assign = [
            'category_id'  => $category->id,
            'articles'     => $articles,
            'tagName'      => '',
            'title'        => $category->name,
            'head'         => $head,
        ];

        return view('home.index.index', $assign);
    }

    public function tag(Tag $tag)
    {
        // 获取标签下的文章
        $articles = $tag->articles()
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title'       => $tag->name,
            'keywords'    => $tag->keywords,
            'description' => $tag->description,
        ];
        $assign = [
            'category_id'  => 'index',
            'articles'     => $articles,
            'tagName'      => $tag->name,
            'title'        => $tag->name,
            'head'         => $head,
        ];

        return view('home.index.index', $assign);
    }

    public function note()
    {
        $notes   = Note::orderBy('created_at', 'desc')->get();
        $assign  = [
            'category_id'  => 'note',
            'notes'        => $notes,
            'title'        => '随言碎语',
        ];

        return view('home.index.note', $assign);
    }

    public function openSource()
    {
        $assign = [
            'category_id' => 'openSource',
            'title'       => '开源项目',
        ];

        return view('home.index.openSource', $assign);
    }

    public function comment(Store $request, Comment $commentModel, SocialiteUser $socialiteUserModel)
    {
        // 获取用户id
        $userId = auth()->guard('socialite')->user()->id;
        // 如果用户输入邮箱；则将邮箱记录入socialite_user表中
        $email = $request->input('email', '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            // 修改邮箱
            SocialiteUser::where('id', $userId)->update([
                'email' => $email,
            ]);
        }

        // 存储评论
        $comment = Comment::create($request->only('article_id', 'content', 'pid') + [
            'socialite_user_id' => $userId,
            'type'              => 1,
            'is_audited'        => Str::isTrue(config('bjyblog.comment_audit')) ? 0 : 1,
        ]);

        return response()->json(['id' => $comment->id]);
    }

    public function checkLogin()
    {
        return response()->json([
            'status' => (int) auth()->guard('socialite')->check(),
        ]);
    }

    public function search(Request $request, Article $articleModel)
    {
        // 禁止蜘蛛抓取搜索页
        if (Agent::isRobot()) {
            abort(404);
        }

        $wd = clean($request->input('wd'));

        $id = $articleModel->searchArticleGetId($wd);

        // 获取文章列表数据
        $articles = Article::select(
                'id', 'category_id', 'title',
                'author', 'description', 'cover',
                'is_top', 'created_at'
            )
            ->whereIn('id', $id)
            ->orderBy('created_at', 'desc')
            ->with(['category', 'tags'])
            ->paginate(10);
        $head = [
            'title'       => $wd,
            'keywords'    => '',
            'description' => '',
        ];
        $assign = [
            'category_id'  => 'index',
            'articles'     => $articles,
            'tagName'      => '',
            'title'        => $wd,
            'head'         => $head,
        ];

        // 增加 X-Robots-Tag 用于禁止搜搜引擎抓取搜索结果页面 防止利用搜索结果页生成恶意广告
        return response()->view('home.index.index', $assign)
            ->header('X-Robots-Tag', 'noindex');
    }

    public function feed()
    {
        // 获取文章
        $article = Article::select('id', 'author', 'title', 'description', 'html', 'created_at')
            ->latest()
            ->get();

        $feed              = App::make('feed');
        $feed->title       = config('app.name');
        $feed->description = config('bjyblog.head.description');
        $feed->logo        = asset('uploads/avatar/1.jpg');
        $feed->link        = url('feed');
        $feed->setDateFormat('carbon');
        $feed->pubdate     = $article->first()->created_at;
        $feed->lang        = config('app.locale');
        $feed->ctype       = 'application/xml';

        foreach ($article as $v) {
            $feed->add($v->title, $v->author, url('article', $v->id), $v->created_at, $v->description);
        }

        return $feed->render('atom');
    }
}
