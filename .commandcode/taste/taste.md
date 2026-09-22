# Taste

- Gives terse, informal prompts with minimal context (e.g., "check this folder and tell me bout this") and expects the assistant to autonomously inspect the project and report back with a concise orientation/overview. Confidence: 0.5
- Prefers turnkey, double-clickable Windows .bat scripts that perform a whole workflow end-to-end with zero manual steps ("i will just dubble click and it will all done perfectly") — scripts should be idempotent/re-runnable and handle edge cases (e.g., existing git remote) rather than failing. Wants one click after any code change to commit everything and push. Confidence: 0.8
- Uses a dual-remote git setup and wants every push mirrored to both a personal GitHub account (Arafat-plugins) and the GoodTechies organization repo from the same single action/script. Confidence: 0.8
- Wants local-only helper/tooling scripts (e.g., the push-to-github.bat) excluded via .gitignore so they never get pushed to GitHub and stay on the local machine only. Confidence: 0.8
