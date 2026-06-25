-- Track whether the agent has confirmed a local RUM beacon key.
ALTER TABLE site_perf_config
    ADD COLUMN IF NOT EXISTS rum_agent_beacon_key_set boolean;

ALTER TABLE site_perf_config
    ADD COLUMN IF NOT EXISTS rum_agent_beacon_key_reported_at timestamptz;
