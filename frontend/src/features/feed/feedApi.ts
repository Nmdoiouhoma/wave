// W-34: the operations the feed needs, as one injectable object (tests of FeedPlayback pass fakes).
import { api } from '@/api/instance';
import type { FeedSource, Post, PostViewReport } from '@/api/types';

export type { FeedSource, Post, PostViewReport };

export const feedApi = {
  page: (source: FeedSource, cursor: string | null, limit?: number) =>
    api.feed.page(source, { cursor, limit }),
  post: (id: string) => api.posts.get(id),
  streamUrl: (id: string) => api.posts.streamUrl(id),
  like: (id: string) => api.posts.like(id),
  unlike: (id: string) => api.posts.unlike(id),
  reportViews: (views: PostViewReport[], opts: { keepalive?: boolean } = {}) =>
    api.posts.reportViews(views, opts),
  /** DATA_MODEL §11: feed listen, reference = clip duration, grant from the clip URL. */
  reportListen: (post: Post, listenedMs: number, opts: { keepalive?: boolean } = {}) =>
    api.tracks.reportListen(
      post.track.id,
      { listenedMs, source: 'feed', postId: post.id },
      { keepalive: opts.keepalive },
    ),
};

export type FeedApi = typeof feedApi;
