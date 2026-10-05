// text.js — every string the popup, prompt and options pages show, in one place.
//
// Placeholders are written as the shape they stand for (HH:MM, MM:SS, M:SS)
// and replaced at runtime with String.prototype.replace.

export const TEXT = Object.freeze({
    // Product
    APP_NAME: 'goodERP Timer',
    VERSION_LABEL: 'Version',
    VERSION: '0.1.0',

    // Disclosure (first run in the popup; always on the options page)
    DISCLOSURE_HEADING: 'What this extension records',
    DISCLOSURE: 'While your timer is running, this extension records which website domain you are on (for example docs.google.com) and how long, whether you are active, and whether a video or a call is playing. It never records page addresses, page titles, page content, what you type, or screenshots. When the timer is paused or stopped, nothing is recorded.',
    DISCLOSURE_OK: 'Got it',

    // Pairing
    PAIR_HEADING: 'Connect this browser',
    RECONNECT_HEADING: 'Reconnect',
    RECONNECT_LINE: 'The connection ended. Get a new code to connect again.',
    SERVER_LABEL: 'Server address',
    CODE_LABEL: 'Connection code',
    DEVICE_LABEL: 'Device name',
    DEVICE_DEFAULT_CHROME: 'Chrome extension',
    DEVICE_DEFAULT_EDGE: 'Edge extension',
    CONNECT: 'Connect',
    CONNECTING: 'Connecting…',
    WHERE_CODE: 'Where do I get a code?',
    WHERE_CODE_ANSWER: 'In goodERP, open your Profile and choose Connect timer extension. Copy the 8-character code and paste it here. A code works once, for 10 minutes.',
    CODE_TOO_SHORT: 'The code has 8 characters.',
    PERMISSION_DENIED: 'The extension was not allowed to reach that server, so it cannot connect.',

    // Timer
    TASK_LABEL: 'Task',
    TASK_PLACEHOLDER: 'Choose a task',
    NO_TASKS: 'There are no open tasks to time.',
    START: 'Start',
    PAUSE: 'Pause',
    RESUME: 'Resume',
    STOP: 'Stop',
    STATUS_RUNNING: 'Running',
    STATUS_PAUSED: 'Paused',
    TIMER_LABEL: 'Time on this entry',
    TODAY_LABEL: 'Today',
    TODAY_OF: 'of',
    LEFT_TODAY: 'left today',
    MEETING_BUTTON: "I'm in a meeting",
    MEETING_LEFT: 'In a meeting · MM:SS left',
    MEETING_CANCEL: 'Cancel',
    SETTINGS: 'Settings',

    // Status and errors
    OFFLINE: 'Offline — changes are saved when the connection is back',
    ERROR_GENERIC: 'Something went wrong. Please try again.',
    ERROR_INVALID_SERVER: 'That server address is not valid.',
    ERROR_NOT_PAIRED: 'This browser is not connected yet.',
    ERROR_NO_ENTRY: 'There is no timer to change.',

    // Idle prompt
    PROMPT_TITLE: 'Are you still working?',
    PROMPT_INACTIVE: 'You have been inactive for M:SS',
    PROMPT_PAUSES_AT: 'The timer pauses by itself at HH:MM',
    PROMPT_KEEP: 'Keep this time and continue',
    PROMPT_DISCARD: 'Discard the idle time and continue',
    PROMPT_MEETING: 'I was in a meeting or call (keep the time)',
    PROMPT_STOP: 'Stop the timer',
    PROMPT_PAUSED_HEADING: 'Timer paused at HH:MM',
    PROMPT_PAUSED_LINE: 'No answer came in time, so the timer was paused at the moment the inactivity began.',

    // Options page
    OPTIONS_TITLE: 'goodERP Timer settings',
    SERVER_HEADING: 'Server',
    SAVE: 'Save',
    SAVED: 'Saved.',
    SERVER_LOCKED: 'Disconnect first to change the server',
    WARNING_HEADING: 'About the install warning',
    WARNING: `Chrome and Edge warn that this extension can "read and change all your data on all websites". That permission is what lets it notice a playing video or a live call on any page. It reads two yes/no facts from a page and the website's domain name, and nothing else.`,
    LIMITS_HEADING: 'Known limits',
    LIMIT_DESKTOP_CALLS: "A call in a desktop app — Zoom, Teams, Discord desktop, or a phone — cannot be seen. Use I'm in a meeting in the popup, or the meeting button on the inactivity prompt.",
    LIMIT_AUDIBLE: 'Any tab playing sound, music included, counts as media, so that time is not idle.',
    DEVICE_HEADING: 'This browser',
    PAIRED_AS: 'Connected as',
    NOT_PAIRED: 'Not connected. Click the extension icon to connect.',
    DISCONNECT: 'Disconnect',
    DISCONNECTED: 'Disconnected.',
    INSTALL_HEADING: 'Install in short',
    INSTALL_STEPS: Object.freeze([
        'Open chrome://extensions (or edge://extensions in Edge) and turn on Developer mode.',
        'Choose Load unpacked and pick the goodtechies-timer-0.1.0 folder.',
        'In goodERP, open Profile → Connect timer extension and copy the 8-character code.',
        'Click the extension icon, paste the code and press Connect.',
        'Pin the icon so the timer is always one click away.',
    ]),
});
