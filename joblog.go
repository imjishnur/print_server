//go:build windows

package main

import (
	_ "embed"
	"encoding/base64"
	"net"
	"net/http"
	"strconv"
	"sync"
	"time"
)

// Print log: the last print jobs kept in memory (lost on restart), shown on
// /print_server/log with the JSON the POS sent and a preview of the receipt.
// Only reachable from this PC, because jobs contain customer names and orders.

const maxJobs = 100

//go:embed joblog.html
var jobLogPage []byte

type printJob struct {
	ID         int64     `json:"id"`
	Time       time.Time `json:"time"`
	Kind       string    `json:"kind"` // v1 (receipt built here) or v2 (image from the browser)
	PrintType  string    `json:"print_type"`
	OrderNo    string    `json:"order_no"`
	Printer    string    `json:"printer"`
	Method     string    `json:"method"`
	Success    bool      `json:"success"`
	Error      string    `json:"error,omitempty"`
	DurationMs int64     `json:"duration_ms"`
	Remote     string    `json:"remote"`
	Bytes      int       `json:"bytes"`

	request  string            // content_data / request body exactly as received
	form     map[string]string // other POST fields
	image    string            // v2 receipt image (base64 PNG)
	output   []byte            // ESC/POS bytes sent to the printer
	response obj
	start    time.Time
}

type jobLog struct {
	mu     sync.Mutex
	nextID int64
	jobs   []*printJob // oldest first
}

func (l *jobLog) add(j *printJob) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.nextID++
	j.ID = l.nextID
	l.jobs = append(l.jobs, j)
	if len(l.jobs) > maxJobs {
		l.jobs = l.jobs[len(l.jobs)-maxJobs:]
	}
}

func (l *jobLog) list() []printJob {
	l.mu.Lock()
	defer l.mu.Unlock()
	out := make([]printJob, 0, len(l.jobs))
	for i := len(l.jobs) - 1; i >= 0; i-- {
		out = append(out, *l.jobs[i])
	}
	return out
}

func (l *jobLog) get(id int64) *printJob {
	l.mu.Lock()
	defer l.mu.Unlock()
	for _, j := range l.jobs {
		if j.ID == id {
			return j
		}
	}
	return nil
}

// newJob captures the request before it is printed.
func newJob(r *http.Request, post map[string]string, raw []byte) *printJob {
	j := &printJob{Time: time.Now(), start: time.Now(), form: map[string]string{}}
	j.Remote, _, _ = net.SplitHostPort(r.RemoteAddr)
	for k, v := range post {
		switch k {
		case "content_data":
			j.request = v
		case "image_base64":
			j.image = v
		default:
			j.form[k] = v
		}
	}
	if j.request == "" && len(raw) > 0 {
		j.request = string(raw)
	}
	return j
}

// reply records the result of a print job and sends the response to the POS.
func (a *Agent) reply(w http.ResponseWriter, j *printJob, status int, res obj) {
	j.Success, _ = res["success"].(bool)
	j.Error, _ = res["error"].(string)
	j.Method, _ = res["method"].(string)
	j.response = res
	j.Bytes = len(j.output)
	j.DurationMs = time.Since(j.start).Milliseconds()
	a.jobs.add(j)
	writeJSON(w, status, res)
}

func localOnly(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !isLocal(r) {
			http.Error(w, "The print log can only be opened on the print PC (http://localhost/print_server/log)", http.StatusForbidden)
			return
		}
		next(w, r)
	}
}

// isLocal: the request comes from this PC, by localhost or by one of its own IPs
// (opening http://192.168.x.x/print_server/ on the print PC itself).
func isLocal(r *http.Request) bool {
	host, _, _ := net.SplitHostPort(r.RemoteAddr)
	ip := net.ParseIP(host)
	if ip == nil {
		return false
	}
	if ip.IsLoopback() {
		return true
	}
	addrs, _ := net.InterfaceAddrs()
	for _, a := range addrs {
		if n, ok := a.(*net.IPNet); ok && n.IP.Equal(ip) {
			return true
		}
	}
	return false
}

func (a *Agent) handleLogPage(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	_, _ = w.Write(jobLogPage)
}

func (a *Agent) handleJobs(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, a.jobs.list())
}

func (a *Agent) handleJob(w http.ResponseWriter, r *http.Request) {
	id, _ := strconv.ParseInt(r.PathValue("id"), 10, 64)
	j := a.jobs.get(id)
	if j == nil {
		http.NotFound(w, r)
		return
	}
	writeJSON(w, http.StatusOK, obj{
		"job":      j,
		"request":  j.request,
		"form":     j.form,
		"image":    j.image,
		"output":   base64.StdEncoding.EncodeToString(j.output),
		"response": j.response,
	})
}
