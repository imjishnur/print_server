//go:build windows

package main

import (
	"time"

	"github.com/getlantern/systray"
	"golang.org/x/sys/windows"
)

func runTray(a *Agent) {
	systray.Run(func() { onTrayReady(a) }, func() {})
}

func onTrayReady(a *Agent) {
	systray.SetTitle("Print Server")

	status := systray.AddMenuItem("", "")
	status.Disable()
	systray.AddSeparator()
	open := systray.AddMenuItem("Open status page", "Printers, test print, IP address")
	printLog := systray.AddMenuItem("Open print log", "Last print jobs: JSON sent and receipt preview")
	logs := systray.AddMenuItem("Open logs folder", "")
	systray.AddSeparator()
	toggle := systray.AddMenuItem("Stop server", "Free ports 80/443 without quitting")
	quit := systray.AddMenuItem("Quit", "Stop Print Server (printing stops)")

	refresh := func() {
		line := a.statusLine()
		status.SetTitle(line)
		systray.SetTooltip("Print Server - " + line)
		systray.SetIcon(trayIcon(a.healthy()))
		if a.isStopped() {
			toggle.SetTitle("Start server")
			open.Disable()
		} else {
			toggle.SetTitle("Stop server")
			open.Enable()
		}
	}
	refresh()

	go func() {
		for {
			select {
			case <-open.ClickedCh:
				shellOpen(a.statusURL())
			case <-printLog.ClickedCh:
				shellOpen(a.printLogURL())
			case <-logs.ClickedCh:
				shellOpen(a.logsDir())
			case <-toggle.ClickedCh:
				if a.isStopped() {
					a.start()
				} else {
					a.stop()
				}
				refresh()
			case <-quit.ClickedCh:
				systray.Quit()
				return
			case <-time.After(30 * time.Second):
				refresh()
			}
		}
	}()
}

func shellOpen(target string) {
	verb, _ := windows.UTF16PtrFromString("open")
	file, _ := windows.UTF16PtrFromString(target)
	_ = windows.ShellExecute(0, verb, file, nil, nil, windows.SW_SHOWNORMAL)
}
