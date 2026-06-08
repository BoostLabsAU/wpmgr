package media

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/riverqueue/river"

	"github.com/mosamlife/wpmgr/apps/api/internal/media/font"
)

// FontRiverEnqueuer inserts font_transcode jobs into River. It is a PURE-Go
// type with no CGO dependency so both the main API and the media-encoder
// process can use it. The main API registers the font_transcode queue with
// MaxWorkers=0 (insert-only); the media-encoder runs the workers.
type FontRiverEnqueuer struct {
	client *river.Client[pgx.Tx]
}

// NewFontRiverEnqueuer builds the enqueuer.
func NewFontRiverEnqueuer(client *river.Client[pgx.Tx]) *FontRiverEnqueuer {
	return &FontRiverEnqueuer{client: client}
}

// EnqueueTranscode inserts one font_transcode job and returns the assigned
// River job ID.
func (e *FontRiverEnqueuer) EnqueueTranscode(ctx context.Context, args font.TranscodeArgs) (int64, error) {
	res, err := e.client.Insert(ctx, args, nil)
	if err != nil {
		return 0, fmt.Errorf("enqueue font_transcode: %w", err)
	}
	return res.Job.ID, nil
}
