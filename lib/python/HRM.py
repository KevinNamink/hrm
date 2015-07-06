#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Support for various HRM related tasks.

Classes
-------

JobDescription()
    Parser for job descriptions, works on files or strings.

JobQueue()
    Job handling and scheduling.
"""

import ConfigParser
import pprint
import time
import os
import sys
from collections import deque
from hashlib import sha1  # ignore this bug in pylint: disable-msg=E0611

# GC3Pie imports
try:
    import gc3libs
except ImportError:
    print("ERROR: unable to import GC3Pie library package, please make sure")
    print("it is installed and active, e.g. by running this command before")
    print("starting the HRM Queue Manager:")
    print("\n$ source /path/to/your/gc3pie_installation/bin/activate\n")
    sys.exit(1)

from gc3libs.config import Configuration

from hrm_logger import warn, info, debug, set_loglevel

import logging
# we set a default loglevel and add some shortcuts for logging:
loglevel = logging.WARN
gc3libs.configure_logger(loglevel, "qmgc3")
logw = gc3libs.log.warn
logi = gc3libs.log.info
logd = gc3libs.log.debug
loge = gc3libs.log.error
logc = gc3libs.log.critical

__all__ = ['JobDescription', 'JobQueue']


# expected version for job description files:
JOBFILE_VER = '5'


class JobDescription(dict):

    """Abstraction class for handling HRM job descriptions.

    Read an HRM job description either from a file or a string and parse
    the sections, check them for sane values and store them in a dict.
    """

    def __init__(self, job, srctype, loglevel=None):
        """Initialize depending on the type of description source.

        Parameters
        ----------
        job : string
        srctype : string

        Example
        -------
        >>> job = HRM.JobDescription('/path/to/jobdescription.cfg', 'file')
        """
        super(JobDescription, self).__init__()
        if loglevel is not None:
            set_loglevel(loglevel)
        self.jobparser = ConfigParser.RawConfigParser()
        self._sections = []
        if (srctype == 'file'):
            self.name = "file '%s'" % job
            self._parse_jobfile(job)
        elif (srctype == 'string'):
            # TODO: _parse_jobstring(job)
            self.name = "string received from socket"
            raise Exception("Source type 'string' not yet implemented!")
        else:
            raise Exception("Unknown source type '%s'" % srctype)
        # store the SHA1 digest of this job, serving as the UID:
        # TODO: we could use be the hash of the actual (unparsed) string
        # instead of the representation of the Python object, but therefore we
        # need to hook into the parsing itself (or read the file twice) - this
        # way one could simply use the cmdline utility "sha1sum" to check if a
        # certain job description file belongs to a specific UID.
        self['uid'] = sha1(self.__repr__()).hexdigest()
        pprint.pprint("Finished initialization of JobDescription().")
        pprint.pprint(self)

    def _parse_jobfile(self, fname):
        """Initialize ConfigParser for a file and run parsing method."""
        debug("Parsing jobfile '%s'..." % fname)
        if not os.path.exists(fname):
            raise IOError("Can't find file '%s'!" % fname)
        if not os.access(fname, os.R_OK):
            raise IOError("Can't read file '%s', permission problem!" % fname)
        # sometimes the inotify event gets processed very rapidly and we're
        # trying to parse the file *BEFORE* it has been written to disk
        # entirely, which breaks the parsing, so we introduce four additional
        # levels of waiting time to avoid this race condition:
        for snooze in [0, 0.001, 0.1, 1, 5]:
            if not self._sections and snooze > 0:
                info("Sections are empty, re-trying in %is." % snooze)
            time.sleep(snooze)
            try:
                parsed = self.jobparser.read(fname)
                debug("Parsed file '%s'." % parsed)
            except ConfigParser.MissingSectionHeaderError as err:
                # consider using SyntaxError here!
                raise IOError("ERROR in JobDescription: %s" % err)
            self._sections = self.jobparser.sections()
            if self._sections:
                debug("Job parsing succeeded after %s seconds!" % snooze)
                break
        if not self._sections:
            warn("ERROR: Could not parse '%s'!" % fname)
            raise IOError("Can't parse '%s'" % fname)
        debug("Job description sections: %s" % self._sections)
        self._parse_jobdescription()

    def _parse_jobdescription(self):
        """Parse details for an HRM job and check for sanity.

        Use the ConfigParser object and assemble a dicitonary with the
        collected details that contains all the information for launching a new
        processing task. Raises Exceptions in case something unexpected is
        found in the given file.
        """
        # TODO: group code into parsing and sanity-checking
        # FIXME: currently only deconvolution jobs are supported, until hucore
        # will be able to do the other things like SNR estimation and
        # previewgen using templates as well!
        # parse generic information, version, user etc.
        if not self.jobparser.has_section('hrmjobfile'):
            raise ValueError("Error parsing job from %s." % self.name)
        # version
        try:
            self['ver'] = self.jobparser.get('hrmjobfile', 'version')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find version in %s." % self.name)
        if not (self['ver'] == JOBFILE_VER):
            raise ValueError("Unexpected version in %s." % self['ver'])
        # username
        try:
            self['user'] = self.jobparser.get('hrmjobfile', 'username')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find username in %s." % self.name)
        # useremail
        try:
            self['email'] = self.jobparser.get('hrmjobfile', 'useremail')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find email address in %s." % self.name)
        # timestamp
        try:
            self['timestamp'] = self.jobparser.get('hrmjobfile', 'timestamp')
            # the keyword "on_parsing" requires us to fill in the value:
            if self['timestamp'] == 'on_parsing':
                self['timestamp'] = time.time()
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find timestamp in %s." % self.name)
        # type
        try:
            self['type'] = self.jobparser.get('hrmjobfile', 'jobtype')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find jobtype in %s." % self.name)
        # from here on a jobtype specific parsing must be done:
        if self['type'] == 'hucore':
            self._parse_job_hucore()
        else:
            raise ValueError("Unknown jobtype '%s'" % self['type'])

    def _parse_job_hucore(self):
        """Do the specific parsing of "hucore" type jobfiles.

        Parse the "hucore" and the "inputfiles" sections of HRM job
        configuration files.

        Returns
        -------
        void
            All information is added to the "self" dict.
        """
        # the "hucore" section:
        try:
            self['exec'] = self.jobparser.get('hucore', 'executable')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find executable in %s." % self.name)
        try:
            self['template'] = self.jobparser.get('hucore', 'template')
        except ConfigParser.NoOptionError:
            raise ValueError("Can't find template in %s." % self.name)
        # and the input file(s):
        if not 'inputfiles' in self._sections:
            raise ValueError("No input files defined in %s." % self.name)
        self['infiles'] = []
        for option in self.jobparser.options('inputfiles'):
            infile = self.jobparser.get('inputfiles', option)
            self['infiles'].append(infile)

    def get_category(self):
        """Get the category of this job, in our case the value of 'user'."""
        return self['user']


class JobQueue(object):

    """Class to store a list of jobs that need to be processed.

    An instance of this class can be used to keep track of lists of jobs of
    different categories (e.g. individual users). The instance will contain a
    scheduler so that it is possible for the caller to simply request the next
    job from this queue without having to care about priorities or anything
    else.
    """

    # TODO: implement __len__()
    # TODO: either remove items from jobs[] upon pop() / remove() or add their
    # ID to a list so the jobs[] dict can get garbage-collected later
    def __init__(self):
        """Initialize an empty job queue."""
        self.cats = deque('')  # categories / users, used by the scheduler
        # jobs is a dict containing the JobDescription objects using their
        # UID as the indexing key for fast access:
        self.jobs = dict()
        self.queue = dict()

    def append(self, job):
        """Add a new job to the queue."""
        # TODO: should we catch duplicate jobs? Currently they are enqueued.
        cat = job.get_category()
        uid = job['uid']
        info("Enqueueing job '%s' into category '%s'." % (uid, cat))
        self.jobs[uid] = job  # store the job in the global dict
        if not cat in self.cats:
            warn("Adding a new queue for '%s' to the JobQueue." % cat)
            self.cats.append(cat)
            self.queue[cat] = deque()
            debug("Current queue categories: %s" % self.cats)
        else:
            # in case there are already jobs of this category, we don't touch
            # the scheduler / priority queue:
            debug("JobQueue already contains a queue for '%s'." % cat)
        self.queue[cat].append(uid)
        info("Queue for category '%s': %s" % (cat, self.queue[cat]))
        # debug("Overall list of job descriptions: %s" % self.jobs)

    def pop(self):
        """Return the next job description for processing.

        Picks the next that should be processed from that queue that has the
        topmost position in the categories queue. After selecting the job, the
        categories queue is shifted one to the left, meaning that the category
        of the just picked job is then at the last position in the categories
        queue.
        This implements a very simple round-robin (token based) scheduler that
        is going one-by-one through the existing categories.
        """
        try:
            cat = self.cats[0]
        except IndexError:
            warn('Categories queue is empty, no jobs left!')
            return
        jobid = self.queue[cat].popleft()
        info("Retrieving next job: category '%s', uid '%s'." % (cat, jobid))
        if len(self.queue[cat]) >= 1:
            debug("Shifting category list.")
            self.cats.rotate(-1)  # move the first element to last position
        else:
            debug("Queue for category '%s' now empty, removing it." % cat)
            self.cats.popleft()  # remove it from the categories list
            del self.queue[cat]  # delete the category from the queue dict
        debug("Current queue categories: %s" % self.cats)
        debug("Current contents of all queues: %s" % self.queue)
        return self.jobs[jobid]

    def remove(self, uid):
        """Remove a job with a given UID from the queue.

        Take a job UID, look up the corresponding category for this job and
        remove the job from this category's queue. If this queue is empty
        afterwards, clean up by removing the job's category from the categories
        list and deleting the category deque from the queue dict.

        Parameters
        ----------
        uid : str (UID of job to remove)
        """
        warn("Trying to remove job with uid '%s'." % uid)
        try:
            cat = self.jobs[uid].get_category()
        except KeyError as err:
            warn("No job with uid '%s' was found!" % err)
            return
        debug("Category of job to remove: '%s'." % cat)
        try:
            self.queue[cat].remove(uid)
        except KeyError as err:
            warn("No queue for category %s was found!" % err)
            return
        except ValueError as err:
            warn("No job with uid '%s' in queue! (%s)" % (uid, err))
            return
        debug("Current queue categories: %s" % self.cats)
        debug("Current contents of all queues: %s" % self.queue)
        if len(self.queue[cat]) < 1:
            debug("Queue for category '%s' now empty, removing it." % cat)
            self.cats.remove(cat)  # remove it from the categories list
            del self.queue[cat]    # delete the category from the queue dict
            debug("Current queue categories: %s" % self.cats)
            debug("Current contents of all queues: %s" % self.queue)

    def queue_details_hr(self):
        """Generate a human readable list with the current queue details."""
        cat_index = 0  # pointer for categories
        cmax = len(self.cats)  # number of categories
        cdone = 0
        print('Queue categories: %i' % cmax)
        queues = dict()
        for i in range(len(self.cats)):
            # jobid = self.queue[self.cats[i]]
            queues[self.cats[i]] = 0  # pointers to jobs in separate categories
        print(queues)
        while True:
            cat = self.cats[cat_index]
            # print("Current category: %i (%s)" % (cat_index, cat))
            curqueue = self.queue[cat]
            # print("Current queue: %s" % curqueue)
            # print("Current in-queue pointers: %s" % queues)
            if queues[cat] > -1:
                jobid = curqueue[queues[cat]]
                print("Next job id: %s" % jobid)
                queues[cat] += 1
                if queues[cat] >= len(self.queue[cat]):
                    queues[cat] = -1
                    cdone += 1  # increase counter of processed categories
                    if cdone == cmax:
                        return
            cat_index += 1
            if cat_index >= cmax:
                cat_index = 0
            # print("Category pointer: %i" % cat_index)
            # print("Current in-queue pointers: %s" % queues)


class JobSpooler(object):

    """Spooler class processing the queue, dispatching jobs, etc."""

    def __init__(self, spool_dir, gc3conf=None):
        """Prepare the spooler.

        Check the GC3Pie config file, set up the spool directories, set up the
        gc3 engine, check the resource directories.

        TODO:
         - do the spooling: monitor the queue and dispatch jobs as required
        """
        self.gc3spooldir = None
        self.gc3conf = None
        self._check_gc3conf(gc3conf)
        self.dirs = self.setup_spooltree(spool_dir)
        self.engine = self.setup_engine()
        if not self.resource_dirs_clean():
            raise RuntimeError("GC3 resource dir unclean, refusing to start!")

    def _check_gc3conf(self, gc3conffile=None):
        """Check the gc3 config file and extract the gc3 spooldir.

        Helper method to check the config file and set the instance variables
        self.gc3spooldir : str
            The path name to the gc3 spooling directory.
        self.gc3conf : str
            The file NAME of the gc3 config file.
        """
        # gc3libs methods like create_engine() use the default config in
        # ~/.gc3/gc3pie.conf if none is specified (see API for details)
        if gc3conffile is None:
            gc3conffile = '~/.gc3/gc3pie.conf'
        gc3conf = Configuration(gc3conffile)
        try:
            self.gc3spooldir = gc3conf.resources['localhost'].spooldir
        except AttributeError:
            raise AttributeError("Unable to parse spooldir for resource "
                "'localhost' from gc3pie config file '%s'!" % gc3conffile)
        self.gc3conf = gc3conffile

    def setup_spooltree(self, spool_base):
        """Check if spooling tree exists or try to create it otherwise.

        The expected structure is like this:

        spool_base
            |-- cur
            |-- done
            |-- new
            `-- queue

        Parameters
        ----------
        spool_base : str
            Base path where to set up / check the spool directories.

        Returns
        -------
        full_subdirs : dict
            { 'new'   : '/path/to/spool_base/new',
              'queue' : '/path/to/spool_base/queue',
              'cur'   : '/path/to/spool_base/cur',
              'done'  : '/path/to/spool_base/done' }
        """
        sub_dirs = ['new', 'queue', 'cur', 'done']
        full_subdirs = dict()
        test_dirs = [spool_base]
        for sub_dir in sub_dirs:
            full_subdirs[sub_dir] = os.path.join(spool_base, sub_dir)
            test_dirs.append(full_subdirs[sub_dir])
        for test_dir in test_dirs:
            try:
                if not os.access(test_dir, os.W_OK):
                    os.mkdir(test_dir)
                    logi("Created spool directory '%s'." % test_dir)
            except OSError as err:
                raise OSError("Error creating Queue Manager spooling "
                    "directory '%s': %s" % (test_dir, err))
        return full_subdirs

    def setup_engine(self):
        """Set up the GC3Pie engine."""
        logi('Creating GC3Pie engine using config file "%s".' % self.gc3conf)
        return gc3libs.create_engine(self.gc3conf)

    def select_resource(resource):
        """Select a specific resource for the GC3Pie engine."""
        self.engine.select_resource(resource)

    def resource_dirs_clean(self):
        """Check if the resource dirs of all resources are clean.

        Parameters
        ----------
        engine : gc3libs.core.Engine
            The GC3 engine to check the resource directories for.

        Returns
        -------
        bool
        """
        # NOTE: with the session-based GC3 approach, it should be possible to
        # pick up existing (leftover) jobs in a resource directory upon start
        # and figure out what their status is, clean up, collect results etc.
        for resource in self.engine.get_resources():
            resourcedir = os.path.expandvars(resource.cfg_resourcedir)
            logi("Checking resource dir for resource '%s': %s" %
                (resource.name, resourcedir))
            if not os.path.exists(resourcedir):
                continue
            files = os.listdir(resourcedir)
            if files:
                logw("Resource dir unclean: %s" % files)
                return False
        return True

    def spool(self, jobqueues):
        """Spooler function dispatching jobs from the queues. BLOCKING!"""
        while True:
            try:
                nextjob = jobqueues['hucore'].pop()
                if nextjob is not None:
                    logd("Current joblist: %s" % jobqueues['hucore'].queue)
                    logd("Dispatching next job.")
                    self.run_job(nextjob)
                time.sleep(1)
            except KeyboardInterrupt:
                break
        # TODO: when the spooler gets stopped (e.g. via Ctrl-C or upon request
        # from the web interface or the init script) while a job is still
        # running, it leaves it alone (and thus as well the files transferred
        # for / generated from processing)
        return 0  # stopped on user request (interactive)

    def run_job(self, job):
        """Run a job in a singlethreaded and blocking manner via GC3Pie.

        NOTE: this doesn't mean the process executed during this job is
        singlethreaded, it just means that currently no more than one job is
        run *at a time*.
        """
        # TODO: consider specifying the output dir in the jobfile!
        # -> for now we simply use the gc3spooldir as the output directory to
        # ensure results won't get moved across different storage locations:
        app = HucoreDeconvolveApp(job, self.gc3spooldir)

        # Add your application to the engine. This will NOT submit your
        # application yet, but will make the engine *aware* of the application.
        self.engine.add(app)

        # Periodically check the status of your application.
        laststate = app.execution.state
        curstate = app.execution.state
        while laststate != gc3libs.Run.State.TERMINATED:
            # `Engine.progress()` will do the GC3Pie magic: submit new jobs,
            # update status of submitted jobs, get results of terminating jobs
            # etc...
            self.engine.progress()
            curstate = app.execution.state
            if not (curstate == laststate):
                logw("Job in status %s " % curstate)
            laststate = app.execution.state
            # Wait a few seconds...
            time.sleep(1)
        logw("Job terminated with exit code %s." % app.execution.exitcode)
        logw("The output of the application is in `%s`." %  app.output_dir)
        # hucore EXIT CODES:
        # 0: all went well
        # 143: hucore.bin received the HUP signal (9)
        # 165: the .hgsb file could not be parsed (file missing or with errors)
        return True


class HucoreDeconvolveApp(gc3libs.Application):

    """App object for 'hucore' deconvolution jobs.

    This application calls `hucore` with a given template file and retrives the
    stdout/stderr in a file named `stdout.txt` plus the directories `resultdir`
    and `previews` into a directory `deconvolved` inside the current directory.
    """

    def __init__(self, job, gc3_output):
        logw('Instantiating a HucoreDeconvolveApp:\n%s' % job)
        logi('Job UID: %s' % job['uid'])
        # we need to add the template (with the local path) to the list of
        # files that need to be transferred to the system running hucore:
        job['infiles'].append(job['template'])
        # for the execution on the remote host, we need to strip all paths from
        # this string as the template file will end up in the temporary
        # processing directory together with all the images:
        templ_on_tgt = job['template'].split('/')[-1]
        gc3libs.Application.__init__(
            self,
            arguments = [job['exec'],
                '-exitOnDone',
                '-noExecLog',
                '-checkForUpdates', 'disable',
                '-template', templ_on_tgt],
            inputs = job['infiles'],
            outputs = ['resultdir', 'previews'],
            # collect the results in a subfolder of GC3Pie's spooldir:
            output_dir = os.path.join(gc3_output, 'results_%s' % job['uid']),
            stderr = 'stdout.txt', # combine stdout & stderr
            stdout = 'stdout.txt')


class HucorePreviewgenApp(gc3libs.Application):

    """App object for 'hucore' image preview generation jobs."""

    def __init__(self):
        # logw('Instantiating a HucorePreviewgenApp:\n%s' % job)
        logw('WARNING: this is a stub, nothing is implemented yet!')
        super(HucorePreviewgenApp, self).__init__()


class HucoreEstimateSNRApp(gc3libs.Application):

    """App object for 'hucore' SNR estimation jobs."""

    def __init__(self):
        # logw('Instantiating a HucoreEstimateSNRApp:\n%s' % job)
        logw('WARNING: this is a stub, nothing is implemented yet!')
        super(HucoreEstimateSNRApp, self).__init__()