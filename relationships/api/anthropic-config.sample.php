<?php
/**
 * relationships/api/anthropic-config.sample.php
 *
 * Copy this file to anthropic-config.php IN THIS SAME FOLDER on the server
 * and fill in real values. anthropic-config.php is gitignored -- never
 * commit a real API key.
 *
 * Used by prospecting-agent.php (the Prospecting research agent). Create a
 * key in the Anthropic Console (console.anthropic.com -> API keys); the
 * account needs web search enabled (Console -> Privacy / Tools settings) for
 * the agent's web_search / web_fetch server tools to work.
 */

return [
    'api_key' => 'sk-ant-REPLACE-ME',

    // Model for the research agent. claude-sonnet-5 is the cost-conscious
    // default (~$2/$10 per million tokens); claude-opus-5 is stronger at
    // judgment-heavy research at roughly 2.5x the model cost.
    'model' => 'claude-sonnet-5',

    // Upper bound on web searches per discovery run / per profile run.
    // Web search is billed per search on top of tokens, and each search
    // adds tokens, so this is the main per-run cost dial.
    'max_web_searches' => 8,
    'max_web_searches_profile' => 5,

    // How many discovery runs ("Prospect" commands) one rep may start per
    // day (Eastern). Profiles and claims don't count against this.
    'daily_search_cap_per_rep' => 10,
];
