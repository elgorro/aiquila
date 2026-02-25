package main

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"os"
	"time"
)

// jsonLogger writes NDJSON events to a file (or io.Discard if disabled).
// Each event is one JSON object per line, appended to the file.
type jsonLogger struct {
	cmd   string
	start time.Time
	w     io.Writer
	f     *os.File // non-nil when writing to a real file
}

// openLog opens (or creates + appends to) the NDJSON log file and writes a
// "start" event. If path is empty, a no-op logger (io.Discard) is returned.
func openLog(path, cmd string) (*jsonLogger, error) {
	l := &jsonLogger{cmd: cmd, start: time.Now()}
	if path == "" {
		l.w = io.Discard
		return l, nil
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		return nil, fmt.Errorf("open log file %q: %w", path, err)
	}
	l.f = f
	l.w = f
	return l, nil
}

// Info writes a level=info event.
// kvs is an optional list of key-value pairs: "server", "myserver", "ip", "1.2.3.4", ...
func (l *jsonLogger) Info(step, msg string, kvs ...any) {
	l.emit("info", step, msg, "", kvs)
}

// Error writes a level=error event.
func (l *jsonLogger) Error(step string, err error, kvs ...any) {
	errMsg := ""
	if err != nil {
		errMsg = err.Error()
	}
	l.emit("error", step, "", errMsg, kvs)
}

// Done writes the final summary event with ok and elapsed_s, then flushes.
// Pass the error returned by RunE (nil on success).
func (l *jsonLogger) Done(err error) {
	ok := err == nil
	elapsed := math.Round(time.Since(l.start).Seconds()*100) / 100
	errMsg := ""
	if err != nil {
		errMsg = err.Error()
	}
	l.emit("info", "done", "", errMsg, []any{"ok", ok, "elapsed_s", elapsed})
}

// Close closes the underlying log file (no-op if writing to Discard).
func (l *jsonLogger) Close() {
	if l.f != nil {
		_ = l.f.Close()
	}
}

func (l *jsonLogger) emit(level, step, msg, errStr string, kvs []any) {
	entry := map[string]any{
		"time":  time.Now().UTC().Format(time.RFC3339),
		"level": level,
		"cmd":   l.cmd,
	}
	if step != "" {
		entry["step"] = step
	}
	if msg != "" {
		entry["msg"] = msg
	}
	if errStr != "" {
		entry["error"] = errStr
	}
	for i := 0; i+1 < len(kvs); i += 2 {
		if k, ok := kvs[i].(string); ok {
			entry[k] = kvs[i+1]
		}
	}
	data, _ := json.Marshal(entry)
	_, _ = fmt.Fprintln(l.w, string(data))
}
