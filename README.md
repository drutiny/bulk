# bulk

This extension adds bulk drutiny report running functions to Drutiny using
a queue/worker architecture based on the AMPQ messaging protocol.

## Spin up RabbitMQ with Docker.

A quick way to get an AMPQ service is to spin up RabbitMQ in a docker container.
This extensions ships a simple command that will run this for you:

     drutiny bulk:run-queue-service

This will run in the foreground and can be killed and exited using Ctrl-C. It is
ephemeral so the queue will be lost if you exit the service.

## Sending profile:run jobs to the queue.

To send jobs to the queue, use the `bulk:queue` command. You can either send
individual jobs to the queue one at a time or send a batch of jobs using a file.

### Sending an individual jobs

     drutiny bulk:queue my_custom_profile @sitealias.dev -f html -f csv

The above command will send a job to the queue to `profile:run` the `my_custom_profile`
profile against the `@sitealias.dev` target and render the results in html and csv formats.

### Sending a batch of jobs

    drutiny bulk:queue my_custom_profile --target-list=targets.txt -f html

The above command will send a job to the queue for each line in `targets.txt`
where each line is a target like `drush:@sitealias.dev`.
